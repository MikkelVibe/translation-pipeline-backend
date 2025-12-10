## Sådan kommer du i gang! (Lokalt setup med Laravel herd på windows)

### Krav forinden start

Vær sikker på at du har følgende installeret:

-   **Laravel Herd** --
    https://herd.laravel.com/download/latest/windows\
-   **Git**
-   **Docker**


## Installation og Setup

### 1. Clone dit Repo

``` bash
git clone 
cd
```

### 2. Installer PHP afhængigheder (Dependencies)

``` bash
composer install
```

### 3. Tilføj projektet til Laravel Herd

1.  Åben **Laravel Herd**
2.  Klik på **Add Site**
3.  Vælg projektmappen
4.  Vælg et domæne
5.  Gem

Projektet skulle gerne være tilgængeligt nu på:

    https://translation-pipeline-backend.test/api

Test med:

    https://translation-pipeline-backend.test/api/ping

### 4. Start compose

``` bash
docker compose up -d
```