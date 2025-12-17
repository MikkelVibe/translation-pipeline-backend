from __future__ import annotations


DEFAULT_QE_FIELDS = [
    "title",
    "description",
    "metaTitle",
    "metaDescription",
]


def build_qe_samples(payload: dict) -> tuple[list[dict[str, str]], dict]:
    """
    Expects payload:
      {
        "jobId": "...",
        "productId": "...",
        "sourceLanguage": "da",
        "targetLanguage": "fi",
        "src_fields": {...},
        "mt_fields": {...}
      }

    Returns:
      samples: [{"src": "...", "mt": "..."}, ...]
      meta: {
         "jobId":...,
         "productId":...,
         "sourceLanguage":...,
         "targetLanguage":...,
         "fields":[...]
         }
    """
    job_id = payload.get("jobId")
    product_id = payload.get("productId")
    source_language = payload.get("sourceLanguage")
    target_language = payload.get("targetLanguage")

    src_fields = payload.get("src_fields") or {}
    mt_fields = payload.get("mt_fields") or {}

    samples: list[dict[str, str]] = []
    used_fields: list[str] = []

    for field in DEFAULT_QE_FIELDS:
        src = src_fields.get(field)
        mt = mt_fields.get(field)

        if not _is_non_empty_string(src) or not _is_non_empty_string(mt):
            continue

        samples.append({"src": src.strip(), "mt": mt.strip()})
        used_fields.append(field)

    meta = {
        "jobId": job_id,
        "productId": product_id,
        "sourceLanguage": source_language,
        "targetLanguage": target_language,
        "fields": used_fields,
    }

    return samples, meta


def _is_non_empty_string(value: object) -> bool:
    return isinstance(value, str) and value.strip() != ""
