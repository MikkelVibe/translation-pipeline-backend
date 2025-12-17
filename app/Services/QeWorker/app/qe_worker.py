import json
import os

import pika

from app.comet_scorer import CometQeScorer
from app.messages.qe_message import build_qe_samples

RABBITMQ_HOST = os.getenv("RABBITMQ_HOST", "rabbitmq")
RABBITMQ_PORT = int(os.getenv("RABBITMQ_PORT", "5672"))
RABBITMQ_USER = os.getenv("RABBITMQ_USER", "guest")
RABBITMQ_PASSWORD = os.getenv("RABBITMQ_PASSWORD", "guest")

QE_QUEUE = os.getenv("RABBITMQ_QE_QUEUE", "product_qe_queue")
COMET_MODEL = os.getenv("COMET_MODEL", "Unbabel/wmt20-comet-qe-da")


def main() -> None:
    creds = pika.PlainCredentials(RABBITMQ_USER, RABBITMQ_PASSWORD)
    params = pika.ConnectionParameters(
        host=RABBITMQ_HOST,
        port=RABBITMQ_PORT,
        credentials=creds,
    )

    connection = pika.BlockingConnection(params)
    channel = connection.channel()

    channel.queue_declare(queue=QE_QUEUE, durable=False)
    channel.basic_qos(prefetch_count=1)

    scorer = CometQeScorer(model_name=COMET_MODEL)

    def on_message(ch, method, properties, body: bytes) -> None:
        try:
            payload = json.loads(body.decode("utf-8"))
            samples, meta = build_qe_samples(payload)

            if not samples:
                result = {
                    "jobId": meta.get("jobId"),
                    "productId": meta.get("productId"),
                    "sourceLanguage": meta.get("sourceLanguage"),
                    "targetLanguage": meta.get("targetLanguage"),
                    "model": COMET_MODEL,
                    "field_scores": {},
                    "qe_score": None,
                    "note": "No valid text fields to score",
                }
                print(json.dumps(result, ensure_ascii=False))
                ch.basic_ack(delivery_tag=method.delivery_tag)
                return

            scores = scorer.score(samples)

            result = {
                "jobId": meta.get("jobId"),
                "productId": meta.get("productId"),
                "sourceLanguage": meta.get("sourceLanguage"),
                "targetLanguage": meta.get("targetLanguage"),
                "model": COMET_MODEL,
                "field_scores": dict(zip(meta["fields"], scores)),
                "qe_score": sum(scores) / len(scores),
            }

            print(json.dumps(result, ensure_ascii=False))
            ch.basic_ack(delivery_tag=method.delivery_tag)

        except Exception as e:
            print(f"[QE] Error: {e}")
            ch.basic_nack(delivery_tag=method.delivery_tag, requeue=False)

    channel.basic_consume(
        queue=QE_QUEUE,
        on_message_callback=on_message,
        auto_ack=False,
    )

    print(f"[QE] Waiting for messages on {QE_QUEUE}...")
    channel.start_consuming()


if __name__ == "__main__":
    main()
