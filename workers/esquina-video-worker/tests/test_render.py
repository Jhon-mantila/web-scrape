import unittest
from pathlib import Path

from esquina_video_worker.render import build_ffmpeg_command


class RenderCommandTest(unittest.TestCase):
    def test_uses_nvenc_when_available(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=1000,
            end_ms=4000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=True,
        )
        self.assertIn("h264_nvenc", command)
        self.assertNotIn("libx264", command)

    def test_blurred_background_keeps_full_frame(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="blurred_background",
            width=1080,
            height=1920,
            nvenc=False,
        )
        self.assertIn("-filter_complex", command)
        filters = command[command.index("-filter_complex") + 1]
        self.assertIn("gblur", filters)
        self.assertIn("overlay", filters)
        self.assertIn("[vout]", filters)
        self.assertIn("libx264", command)
        self.assertIn("0:a?", command)

    def test_fit_pads_to_canvas_without_cropping(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=False,
            fit_fill="black",
        )
        filters = command[command.index("-vf") + 1]
        self.assertIn("force_original_aspect_ratio=decrease", filters)
        self.assertIn("flags=lanczos", filters)
        self.assertIn("pad=1080:1920", filters)
        self.assertIn("black", filters)
        self.assertNotIn("crop=", filters)

    def test_fit_blur_fill_uses_gblur_instead_of_empty_bars(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=False,
            fit_fill="blur",
        )
        filters = command[command.index("-filter_complex") + 1]
        self.assertIn("gblur", filters)
        self.assertNotIn("pad=", filters)

    def test_fit_with_template_overlays_instead_of_black_bars(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=False,
            template=Path("cover.png"),
            fit_fill="white",
        )
        self.assertIn("-loop", command)
        self.assertIn("cover.png", command)
        filters = command[command.index("-filter_complex") + 1]
        self.assertIn("overlay", filters)
        self.assertIn("[1:v]", filters)
        self.assertIn("white", filters)

    def test_fit_template_on_blur_keeps_gblur_behind_the_overlay(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=False,
            template=Path("cover.png"),
            fit_fill="blur",
        )
        filters = command[command.index("-filter_complex") + 1]
        self.assertIn("gblur", filters)
        self.assertIn("overlay", filters)
        self.assertIn("cover.png", command)

    def test_fit_part_label_is_drawn_last_over_template(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=False,
            template=Path("cover.png"),
            fit_fill="blur",
            part_label="Parte 2",
            part_label_color="#FFFFFF",
            source_width=1920,
            source_height=1080,
        )
        filters = command[command.index("-filter_complex") + 1]
        self.assertIn("drawtext=", filters)
        self.assertIn("Parte 2", filters)
        self.assertIn("0xFFFFFF", filters)
        self.assertGreater(filters.rfind("drawtext="), filters.rfind("overlay"))
        self.assertIn("y=1556", filters)
        self.assertIn("x=(w-text_w)/2", filters)

    def test_fit_part_label_on_solid_bars_uses_filter_complex(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="fit",
            width=1080,
            height=1920,
            nvenc=False,
            fit_fill="black",
            part_label="Parte 1",
            part_label_color="#00FFAA",
            source_width=1920,
            source_height=1080,
        )
        filters = command[command.index("-filter_complex") + 1]
        self.assertIn("drawtext=", filters)
        self.assertIn("Parte 1", filters)
        self.assertIn("0x00FFAA", filters)
        self.assertIn("[vout]", filters)

    def test_smart_crop_ignores_part_label(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="smart_crop",
            width=1080,
            height=1920,
            nvenc=False,
            part_label="Parte 1",
        )
        filters = command[command.index("-vf") + 1]
        self.assertNotIn("drawtext=", filters)

    def test_smart_crop_does_not_upscale_small_crops(self) -> None:
        command = build_ffmpeg_command(
            source=Path("in.mp4"),
            dest=Path("out.mp4"),
            start_ms=0,
            end_ms=2000,
            framing="smart_crop",
            width=1080,
            height=1920,
            nvenc=False,
        )
        filters = command[command.index("-vf") + 1]
        self.assertIn("min(iw", filters)
        self.assertIn("lanczos", filters)
        self.assertNotIn("scale=1080:1920", filters)
