from __future__ import annotations

from typing import Any

from comet import download_model, load_from_checkpoint


class CometQeScorer:
    def __init__(self, model_name: str, batch_size: int = 16) -> None:
        self.model_name = model_name
        self.batch_size = batch_size

        model_path = download_model(model_name)
        self.model = load_from_checkpoint(model_path)

    def score(self, samples: list[dict[str, str]]) -> list[float]:
        """
        samples: [{"src": "...", "mt": "..."}, ...]
        returns: list of floats (one score per sample)
        """
        if not samples:
            return []

        out: Any = self.model.predict(
            samples,
            batch_size=min(self.batch_size, len(samples)),
            gpus=0,
        )

        return [float(s) for s in out.scores]
