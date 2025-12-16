<?php

// Config file for RabbitMQ
return [
   // Connection
   "connection" => [
      "host" => env("RABBITMQ_HOST", "localhost"),
      "port" => (int) env("RABBITMQ_PORT", 5672),
      "user" => env("RABBITMQ_USE","guest"),
      "password"=> env("RABBITMQ_PASSWORD","guest"),

      // Queues
      "queues" => [
         "translate" => env("RABBITMQ_TRANSLATE_QUEUE", "product_translate_queue"),
      ],
   ]
   ];