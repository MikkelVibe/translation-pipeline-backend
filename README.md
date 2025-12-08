## Sådan kommer du i gang! (Lokalt setup med Laravel herd på windows)

### Krav forinden start

Vær sikker på at du har følgende installeret:

-   **Laravel Herd** --
    https://herd.laravel.com/download/latest/windows\
-   **Git**\
-   **Node.js (LTS recommended)**

## Installation og Setup

### 1. Clone dit Repo

``` PowerShell
git clone <REPOSITORY_URL>
Set-Location <PROJECT_FOLDER>
```

``` bash
git clone <REPOSITORY_URL>
cd <PROJECT_FOLDER>
```

### 2. Installer PHP afhængigheder (Dependencies)

``` bash
composer install
```

### 3. Installer Frontend afhængigheder (Dependencies) og Build Assets

``` bash
npm install
npm run build
```

### 4. Tilføj projektet til Laravel Herd

1.  Åben **Laravel Herd**
2.  Klik på **Add Site**
3.  Vælg projektmappen
4.  Vælg et domæne
5.  Gem

Projektet skulle gerne være tilgængeligt nu på:

    https://translation-pipeline-backend.test/api

Test med:

    https://translation-pipeline-backend.test/api/ping
