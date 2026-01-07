<?php

// Config file for RabbitMQ
return [
   // Connection
   "host" => env("RABBITMQ_HOST", "localhost"),
   "port" => (int) env("RABBITMQ_PORT", 5672),
   "user" => env("RABBITMQ_USER","guest"),
   "password"=> env("RABBITMQ_PASSWORD","guest"),

   // Queues
   "queues" => [
      "product_translate" => env("RABBITMQ_TRANSLATE_QUEUE", "product_translate_queue"),
      "product_qe" => env("RABBITMQ_QE_QUEUE", "product_qe_queue"),
      "product_fetch" => env("RABBITMQ_PRODUCT_FETCH_QUEUE", "product_fetch_queue"),
      "product_translation_persist" => env("RABBITMQ_PRODUCT_TRANSLATION_PERSIST_QUEUE", "product_translation_persist_queue"),
   ],
];
