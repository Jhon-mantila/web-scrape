import unittest

from esquina_video_worker.segment import propose_segments


def coverage(segments, duration_ms: int) -> None:
    assert segments, "debe haber al menos un segmento"
    assert segments[0].start_ms == 0
    assert segments[-1].end_ms == duration_ms
    for previous, current in zip(segments, segments[1:]):
        assert previous.end_ms == current.start_ms, "no debe haber huecos ni solapes"


class ProposeSegmentsTest(unittest.TestCase):
    def test_does_not_split_into_equal_intervals_when_scenes_exist(self) -> None:
        duration_ms = 380_000
        target = 7
        equal = duration_ms / target
        scenes = [
            (0, 40_000),
            (40_000, 95_000),
            (95_000, 150_000),
            (150_000, 210_000),
            (210_000, 280_000),
            (280_000, 380_000),
        ]
        silences = [
            (38_000, 41_000),
            (93_000, 96_000),
            (148_000, 152_000),
            (208_000, 212_000),
            (277_000, 282_000),
            (330_000, 333_000),
        ]

        result = propose_segments(
            duration_ms=duration_ms,
            target_count=target,
            min_ms=15_000,
            max_ms=60_000,
            scenes=scenes,
            silences=silences,
            mode="smart",
        )

        lengths = [item.end_ms - item.start_ms for item in result.segments]
        coverage(result.segments, duration_ms)
        self.assertFalse(all(abs(length - equal) < 2_000 for length in lengths))
        self.assertTrue(all(item.source != "equal_interval" for item in result.segments))

    def test_prefers_fewer_clips_instead_of_cutting_a_scene_without_pause(self) -> None:
        duration_ms = 90_000
        scenes = [(0, 40_000), (40_000, 90_000)]
        result = propose_segments(
            duration_ms=duration_ms,
            target_count=7,
            min_ms=15_000,
            max_ms=60_000,
            scenes=scenes,
            silences=[],
            mode="smart",
        )

        coverage(result.segments, duration_ms)
        self.assertEqual(len(result.segments), 2)
        self.assertEqual(result.recommended_count, 2)
        self.assertIsNotNone(result.reason)
        self.assertIn("7", result.reason or "")

    def test_scene_mode_keeps_detected_scenes(self) -> None:
        scenes = [(0, 12_000), (12_000, 40_000), (40_000, 55_000)]
        result = propose_segments(
            duration_ms=55_000,
            target_count=3,
            min_ms=5_000,
            max_ms=60_000,
            scenes=scenes,
            silences=[],
            mode="scene",
        )

        coverage(result.segments, 55_000)
        self.assertEqual(
            [(item.start_ms, item.end_ms) for item in result.segments],
            scenes,
        )


if __name__ == "__main__":
    unittest.main()
