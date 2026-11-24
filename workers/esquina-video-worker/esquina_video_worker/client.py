from __future__ import annotations

import json
import mimetypes
import uuid
from pathlib import Path
from typing import Any
from urllib.error import HTTPError
from urllib.request import Request, urlopen

from esquina_video_worker.logging_setup import configure_logging, format_log_record


class LaravelClient:
    def __init__(self, api_url: str, token: str, timeout: int = 120) -> None:
        self.api_url = api_url.rstrip("/")
        self.token = token
        self.timeout = timeout
        self.log = configure_logging(token)

    def heartbeat(self, capabilities: dict[str, Any]) -> dict[str, Any]:
        return self._json("POST", "/worker/v1/heartbeat", {"capabilities": capabilities})

    def claim(self) -> dict[str, Any] | None:
        status, payload = self._request("POST", "/worker/v1/jobs/claim", json_body={})
        if status == 204:
            return None
        job = (payload or {}).get("job")
        return job if isinstance(job, dict) else None

    def download_source(self, job_id: int, dest: Path) -> Path:
        dest.parent.mkdir(parents=True, exist_ok=True)
        request = Request(
            f"{self.api_url}/worker/v1/jobs/{job_id}/source",
            headers=self._headers(),
            method="GET",
        )
        self.log.info(format_log_record("download", {"url": request.full_url}, self.token))
        with urlopen(request, timeout=self.timeout) as response, dest.open("wb") as handle:
            while True:
                chunk = response.read(1024 * 1024)
                if not chunk:
                    break
                handle.write(chunk)
        return dest

    def download_template(self, job_id: int, dest: Path) -> Path | None:
        dest.parent.mkdir(parents=True, exist_ok=True)
        request = Request(
            f"{self.api_url}/worker/v1/jobs/{job_id}/template",
            headers=self._headers(),
            method="GET",
        )
        try:
            with urlopen(request, timeout=self.timeout) as response, dest.open("wb") as handle:
                while True:
                    chunk = response.read(1024 * 1024)
                    if not chunk:
                        break
                    handle.write(chunk)
        except HTTPError as error:
            if error.code == 404:
                return None
            raise
        return dest if dest.exists() and dest.stat().st_size > 0 else None

    def progress(self, job_id: int, percent: int, message: str, status: str | None = None) -> None:
        body: dict[str, Any] = {
            "progress_percent": percent,
            "progress_message": message,
        }
        if status:
            body["status"] = status
        self._json("PUT", f"/worker/v1/jobs/{job_id}/progress", body)

    def submit_analysis(self, job_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        return self._json("PUT", f"/worker/v1/jobs/{job_id}/analysis", payload)

    def upload_clip(self, job_id: int, index: int, file_path: Path) -> dict[str, Any]:
        return self._multipart(
            f"/worker/v1/jobs/{job_id}/clips/{index}",
            field_name="clip",
            file_path=file_path,
        )

    def fail(self, job_id: int, error_message: str) -> None:
        self._json("POST", f"/worker/v1/jobs/{job_id}/fail", {"error_message": error_message[:5000]})

    def complete(self, job_id: int) -> None:
        self._json("POST", f"/worker/v1/jobs/{job_id}/complete", {})

    def _headers(self, extra: dict[str, str] | None = None) -> dict[str, str]:
        headers = {
            "Authorization": f"Bearer {self.token}",
            "Accept": "application/json",
        }
        if extra:
            headers.update(extra)
        return headers

    def _json(self, method: str, path: str, body: dict[str, Any]) -> dict[str, Any]:
        status, payload = self._request(method, path, json_body=body)
        if status >= 400:
            raise RuntimeError(f"Laravel {method} {path} devolvió {status}: {payload}")
        return payload or {}

    def _request(
        self,
        method: str,
        path: str,
        json_body: dict[str, Any] | None = None,
    ) -> tuple[int, dict[str, Any] | None]:
        data = None if json_body is None else json.dumps(json_body).encode("utf-8")
        headers = self._headers({"Content-Type": "application/json"} if data is not None else None)
        request = Request(f"{self.api_url}{path}", data=data, headers=headers, method=method)
        try:
            with urlopen(request, timeout=self.timeout) as response:
                raw = response.read()
                payload = json.loads(raw.decode("utf-8")) if raw else None
                return response.status, payload if isinstance(payload, dict) else None
        except HTTPError as error:
            raw = error.read()
            try:
                payload = json.loads(raw.decode("utf-8")) if raw else {"message": str(error)}
            except json.JSONDecodeError:
                payload = {"message": raw.decode("utf-8", errors="replace")}
            if error.code == 204:
                return 204, None
            if error.code >= 400:
                raise RuntimeError(f"Laravel {method} {path} devolvió {error.code}: {payload}") from error
            return error.code, payload if isinstance(payload, dict) else None

    def _multipart(self, path: str, field_name: str, file_path: Path) -> dict[str, Any]:
        boundary = uuid.uuid4().hex
        filename = file_path.name
        content_type = mimetypes.guess_type(filename)[0] or "video/mp4"
        file_bytes = file_path.read_bytes()
        body = (
            f"--{boundary}\r\n"
            f'Content-Disposition: form-data; name="{field_name}"; filename="{filename}"\r\n'
            f"Content-Type: {content_type}\r\n\r\n"
        ).encode("utf-8") + file_bytes + f"\r\n--{boundary}--\r\n".encode("utf-8")

        request = Request(
            f"{self.api_url}{path}",
            data=body,
            headers=self._headers({"Content-Type": f"multipart/form-data; boundary={boundary}"}),
            method="POST",
        )
        try:
            with urlopen(request, timeout=max(self.timeout, 600)) as response:
                raw = response.read()
                return json.loads(raw.decode("utf-8")) if raw else {}
        except HTTPError as error:
            raw = error.read()
            try:
                payload = json.loads(raw.decode("utf-8")) if raw else {"message": str(error)}
            except json.JSONDecodeError:
                payload = {"message": raw.decode("utf-8", errors="replace")}
            raise RuntimeError(f"Laravel POST {path} devolvió {error.code}: {payload}") from error
