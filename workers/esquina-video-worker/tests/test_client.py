import unittest
from unittest.mock import patch

from esquina_video_worker.client import LaravelClient
from esquina_video_worker.logging_setup import format_log_record, sanitize_text


class ClaimClientTest(unittest.TestCase):
    def test_claim_returns_none_on_204(self) -> None:
        client = LaravelClient("https://esquina.test", "secret-token")

        class Response:
            status = 204
            headers: dict = {}

            def read(self) -> bytes:
                return b""

            def __enter__(self):
                return self

            def __exit__(self, *args) -> None:
                return None

        with patch("esquina_video_worker.client.urlopen", return_value=Response()):
            self.assertIsNone(client.claim())

    def test_authorization_header_is_not_written_to_logs(self) -> None:
        text = format_log_record(
            "download",
            {
                "method": "GET",
                "url": "https://esquina.test/worker/v1/jobs/1/source",
                "headers": {"Authorization": "Bearer secret-token"},
            },
        )
        self.assertNotIn("secret-token", text)

    def test_sanitize_strips_token_from_message(self) -> None:
        message = sanitize_text("Authorization: Bearer secret-token downloaded ok", "secret-token")
        self.assertNotIn("secret-token", message)


if __name__ == "__main__":
    unittest.main()
