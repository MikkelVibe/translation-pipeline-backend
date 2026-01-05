CREATE TABLE "users" (
  "id" integer PRIMARY KEY,
  "first_name" varchar,
  "last_name" varchar,
  "created_at" timestamp
);

CREATE TABLE "language" (
  "id" integer PRIMARY KEY,
  "code" char(5) UNIQUE NOT NULL,
  "name" varchar
);

CREATE TABLE "prompt" (
  "id" integer PRIMARY KEY,
  "name" varchar,
  "content" text,
  "created_at" timestamp
);

CREATE TABLE "integration" (
  "id" integer PRIMARY KEY,
  "user_id" integer NOT NULL,
  "type" varchar,
  "base_url" varchar,
  "api_key" varchar,
  "created_at" timestamp
);

CREATE TABLE "job" (
  "id" integer PRIMARY KEY,
  "user_id" integer NOT NULL,
  "integration_id" integer,
  "source_lang_id" integer NOT NULL,
  "target_lang_id" integer NOT NULL,
  "prompt_id" integer,
  "status" varchar,
  "total_items" integer,
  "created_at" timestamp,
  "updated_at" timestamp
);

CREATE TABLE "translation" (
  "job_item_id" integer PRIMARY KEY NOT NULL,
  "source_text" text NOT NULL,
  "translated_text" text NOT NULL,
  "language_id" integer NOT NULL,
  "created_at" timestamp
);

CREATE TABLE "job_item" (
  "id" bigint PRIMARY KEY,
  "job_id" integer NOT NULL,
  "external_id" varchar,
  "status" varchar,
  "error_message" text
);

COMMENT ON COLUMN "prompt"."content" IS 'LLM or translation prompt template';

COMMENT ON COLUMN "integration"."type" IS 'e.g. shopware, magento';

COMMENT ON COLUMN "job"."status" IS 'pending, running, completed, failed';

COMMENT ON COLUMN "job_item"."external_id" IS 'e.g. product ID in Shopware';

COMMENT ON COLUMN "job_item"."status" IS 'queued, processing, done, error';

ALTER TABLE "integration" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("id");

ALTER TABLE "job" ADD FOREIGN KEY ("user_id") REFERENCES "users" ("id");

ALTER TABLE "job" ADD FOREIGN KEY ("integration_id") REFERENCES "integration" ("id");

ALTER TABLE "job" ADD FOREIGN KEY ("source_lang_id") REFERENCES "language" ("id");

ALTER TABLE "job" ADD FOREIGN KEY ("target_lang_id") REFERENCES "language" ("id");

ALTER TABLE "job" ADD FOREIGN KEY ("prompt_id") REFERENCES "prompt" ("id");

ALTER TABLE "translation" ADD FOREIGN KEY ("job_item_id") REFERENCES "job_item" ("id");

ALTER TABLE "translation" ADD FOREIGN KEY ("language_id") REFERENCES "language" ("id");

ALTER TABLE "job_item" ADD FOREIGN KEY ("job_id") REFERENCES "job" ("id");
