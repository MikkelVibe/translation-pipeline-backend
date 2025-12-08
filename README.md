## Sådan kommer du i gang! (Lokalt setup med Laravel herd på windows)

### Krav forinden start

Vær sikker på at du har følgende installeret:

-   **Laravel Herd** --
    https://herd.laravel.com/download/latest/windows\
-   **Git**\
-   **Composer**\
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

``` PowerShell
composer install
```

``` bash
composer install
```

### 3. Installer Frontend afhængigheder (Dependencies) og Build Assets

``` PowerShell
npm install
npm run build
```

``` bash
npm install
npm run build
```

### 4. Tilføj projektet til Laravel Herd

1.  Åben **Laravel Herd**
2.  Klik på **Add Site**
3.  Vælg projektmappen
4.  Vælg et domæne (f.eks. `myproject.test`)
5.  Gem

Projektet skulle gerne være tilgængeligt nu på:

    https://myproject.test

## ✅ Færdig!

Herfra skulle backend være installeret, frontenden være bygget, og 
siden er "served" gennem Herd -- og du kan nu udvikle lokalt.