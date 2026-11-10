# JBoard

A lightweight, FigJam-style visual board editor built as a PHP application.

## Features

- Create, edit, rename, and delete boards
- Add text, rectangles, circles, arrows, and images
- Move, resize, style, and organize canvas layers
- Auto-save boards as JSON
- Export boards as self-contained or image-linked JSON
- English and Persian interface support

## Run locally

Requires PHP with the `fileinfo` extension enabled.

```bash
php -S localhost:8000
```

Open [http://localhost:8000](http://localhost:8000) in a browser.

The application automatically creates `boards/` and `uploads/` when first used. Make sure PHP has permission to write to the project directory. These directories are ignored by Git because they contain local board data and uploads.

## Keyboard shortcuts

- `V` — Select
- `T` — Text
- `R` — Rectangle
- `O` — Circle
- `A` — Arrow

## Project structure

- `index.php` — PHP API, HTML, CSS, and JavaScript application
- `boards/` — Persisted board JSON files (generated at runtime)
- `uploads/` — Uploaded images (generated at runtime)