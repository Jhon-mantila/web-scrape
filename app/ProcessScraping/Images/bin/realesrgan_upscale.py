#!/usr/bin/env python3
"""Real-ESRGAN ligero 4× (realesr-general-x4v3 / SRVGGNetCompact)."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

import numpy as np
import onnxruntime as ort
from PIL import Image


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--tile", type=int, default=192)
    parser.add_argument("--overlap", type=int, default=8)
    parser.add_argument("--max-side", type=int, default=2560)
    return parser.parse_args()


def to_nchw(image: Image.Image) -> np.ndarray:
    rgb = np.asarray(image.convert("RGB"), dtype=np.float32) / 255.0
    return np.transpose(rgb, (2, 0, 1))[None, ...]


def from_nchw(tensor: np.ndarray) -> Image.Image:
    rgb = np.clip(tensor[0], 0.0, 1.0)
    hwc = np.transpose(rgb, (1, 2, 0))
    return Image.fromarray((hwc * 255.0).round().astype(np.uint8))


def session_for(model: Path) -> ort.InferenceSession:
    so = ort.SessionOptions()
    so.intra_op_num_threads = 2
    so.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    return ort.InferenceSession(str(model), sess_options=so, providers=["CPUExecutionProvider"])


def run_net(session: ort.InferenceSession, chunk: np.ndarray) -> np.ndarray:
    input_name = session.get_inputs()[0].name
    output_name = session.get_outputs()[0].name
    result = session.run([output_name], {input_name: chunk})[0]
    return np.asarray(result, dtype=np.float32)


def upscale(session: ort.InferenceSession, image: Image.Image, tile: int, overlap: int) -> Image.Image:
    array = to_nchw(image)
    _, _, height, width = array.shape

    if height * width <= tile * tile:
        return from_nchw(run_net(session, array))

    scale = 4
    output = np.zeros((1, 3, height * scale, width * scale), dtype=np.float32)
    weights = np.zeros((1, 1, height * scale, width * scale), dtype=np.float32)
    step = max(1, tile - overlap)

    for top in range(0, height, step):
        for left in range(0, width, step):
            y0 = top
            x0 = left
            y1 = min(top + tile, height)
            x1 = min(left + tile, width)
            if y1 - y0 < 8 or x1 - x0 < 8:
                continue
            tile_in = array[:, :, y0:y1, x0:x1]
            tile_out = run_net(session, tile_in)
            oy0, ox0 = y0 * scale, x0 * scale
            oy1, ox1 = y1 * scale, x1 * scale
            output[:, :, oy0:oy1, ox0:ox1] += tile_out
            weights[:, :, oy0:oy1, ox0:ox1] += 1.0

    weights = np.maximum(weights, 1.0)
    return from_nchw(output / weights)


def fit_max_side(image: Image.Image, max_side: int) -> Image.Image:
    if max_side <= 0:
        return image
    longest = max(image.size)
    if longest <= max_side:
        return image
    ratio = max_side / longest
    size = (max(1, int(image.width * ratio)), max(1, int(image.height * ratio)))
    return image.resize(size, Image.Resampling.LANCZOS)


def main() -> int:
    args = parse_args()
    source = Path(args.input)
    dest = Path(args.output)
    model = Path(args.model)

    if not source.is_file():
        print(f"entrada no existe: {source}", file=sys.stderr)
        return 2
    if not model.is_file():
        print(f"modelo no existe: {model}", file=sys.stderr)
        return 2

    image = Image.open(source)
    image.load()
    session = session_for(model)
    upscaled = upscale(session, image, max(32, args.tile), max(0, args.overlap))
    upscaled = fit_max_side(upscaled, args.max_side)
    dest.parent.mkdir(parents=True, exist_ok=True)
    suffix = dest.suffix.lower()
    if suffix in {".jpg", ".jpeg"}:
        upscaled.save(dest, format="JPEG", quality=92, optimize=True)
    elif suffix == ".webp":
        upscaled.save(dest, format="WEBP", quality=90, method=4)
    else:
        upscaled.save(dest, format="PNG")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
