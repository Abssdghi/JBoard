<?php
/**
 * FigJam-style Visual Board Editor
 * Single-file PHP + HTML + CSS + JS application.
 *
 * PHP API routes (?action=...):
 *   list | create | load&id= | save&id= | delete&id= | upload
 *
 * Data on disk (auto-created):
 *   boards/{uuid}.json
 *   uploads/{uuid}.{ext}
 */

// ============================================================================
// PHP BACKEND
// ============================================================================
declare(strict_types=1);

define('BOARDS_DIR', __DIR__ . '/boards');
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('UUID_PATTERN', '/^[a-f0-9\-]{36}$/');
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

function json_ok($data = null) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function json_err(string $msg, int $code = 400) {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}
function ensure_dirs(): void {
    foreach ([BOARDS_DIR, UPLOADS_DIR] as $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                json_err('cannot create directory: ' . basename($dir), 500);
            }
        }
    }
}
function validate_uuid(?string $id): string {
    if (!is_string($id) || !preg_match(UUID_PATTERN, $id)) {
        json_err('invalid id');
    }
    return $id;
}
function board_path(string $id): string {
    validate_uuid($id);
    return BOARDS_DIR . DIRECTORY_SEPARATOR . $id . '.json';
}
function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// --- Handlers --------------------------------------------------------------

function handle_list(): void {
    $files = glob(BOARDS_DIR . '/*.json') ?: [];
    $boards = [];
    foreach ($files as $f) {
        $raw = @file_get_contents($f);
        if ($raw === false) continue;
        $b = json_decode($raw, true);
        if (!is_array($b) || !isset($b['id'])) continue;
        $boards[] = [
            'id'        => $b['id'],
            'title'     => $b['title'] ?? 'Untitled Board',
            'createdAt' => $b['createdAt'] ?? date('c'),
            'updatedAt' => $b['updatedAt'] ?? date('c'),
        ];
    }
    usort($boards, fn($a, $b) => strcmp($b['updatedAt'], $a['updatedAt']));
    json_ok($boards);
}

function handle_create(): void {
    $id = uuid_v4();
    $now = date('c');
    $board = [
        'id'        => $id,
        'title'     => 'Untitled Board',
        'createdAt' => $now,
        'updatedAt' => $now,
        'canvas'    => ['background' => '#faf9f6'],
        'layers'    => [],
    ];
    $path = board_path($id);
    if (@file_put_contents($path, json_encode($board, JSON_PRETTY_PRINT)) === false) {
        json_err('failed to write board file', 500);
    }
    json_ok(['id' => $id]);
}

function handle_load(): void {
    $id = validate_uuid($_GET['id'] ?? null);
    $path = board_path($id);
    if (!is_file($path)) json_err('board not found', 404);
    $raw = @file_get_contents($path);
    if ($raw === false) json_err('cannot read board file', 500);
    $board = json_decode($raw, true);
    if ($board === null) json_err('board json corrupted', 500);
    json_ok($board);
}

function handle_save(): void {
    $id = validate_uuid($_GET['id'] ?? null);
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') json_err('empty body');
    $board = json_decode($raw, true);
    if (!is_array($board)) json_err('invalid json body');
    if (!isset($board['layers']) || !is_array($board['layers'])) json_err('missing layers');
    $board['id'] = $id;
    $board['updatedAt'] = date('c');
    $path = board_path($id);
    if (@file_put_contents($path, json_encode($board, JSON_PRETTY_PRINT)) === false) {
        json_err('failed to save board', 500);
    }
    json_ok(['updatedAt' => $board['updatedAt']]);
}

function handle_delete(): void {
    $id = validate_uuid($_GET['id'] ?? null);
    $path = board_path($id);
    if (!is_file($path)) json_err('board not found', 404);
    if (!@unlink($path)) json_err('failed to delete board', 500);
    json_ok(['deleted' => $id]);
}

function handle_upload(): void {
    if (empty($_FILES['file'])) json_err('no file uploaded');
    $f = $_FILES['file'];
    if (!is_uploaded_file($f['tmp_name'])) json_err('invalid upload');
    if ($f['size'] > MAX_UPLOAD_SIZE) json_err('file too large (max 10MB)');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = @mime_content_type($f['tmp_name']);
    if (!$mime || !isset($allowed[$mime])) json_err('invalid mime type');

    $ext = $allowed[$mime];
    $fname = uuid_v4() . '.' . $ext;
    $dest = UPLOADS_DIR . DIRECTORY_SEPARATOR . $fname;
    if (!@move_uploaded_file($f['tmp_name'], $dest)) json_err('failed to save upload', 500);
    json_ok(['url' => 'uploads/' . $fname]);
}

// --- Routing ---------------------------------------------------------------

$action = $_GET['action'] ?? null;

if ($action === null) {
    // Serve the HTML app. No JSON header.
    ?><!DOCTYPE html>
<html lang="en" data-theme="light" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Board Editor</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ============================================================================
   CSS VARIABLES
   ========================================================================= */
:root {
    --bg: #faf9f6;
    --panel: #f5f3ef;
    --panel-2: #eeece6;
    --panel-hover: #e8e5dd;
    --border: #ddd8cf;
    --border-strong: #c8c2b6;
    --text: #1a1a1a;
    --text-muted: #6b6560;
    --text-subtle: #8a847d;
    --accent: #2d2d2d;
    --accent-text: #ffffff;
    --accent-soft: #5b6cff;
    --danger: #d93b3b;
    --success: #2e8b57;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --shadow: 0 4px 16px rgba(0,0,0,0.08);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.14);
    --radius-sm: 6px;
    --radius: 10px;
    --radius-lg: 14px;
    --radius-full: 999px;
    --font: 'Inter', system-ui, -apple-system, sans-serif;
    --font-fa: 'Vazirmatn', 'Inter', system-ui, sans-serif;
    --transition: 140ms cubic-bezier(0.4, 0, 0.2, 1);
}
[data-theme="dark"] {
    --bg: #1a1a1a;
    --panel: #232323;
    --panel-2: #2b2b2b;
    --panel-hover: #333333;
    --border: #3a3a3a;
    --border-strong: #4a4a4a;
    --text: #ececec;
    --text-muted: #a0a0a0;
    --text-subtle: #808080;
    --accent: #e8e8e8;
    --accent-text: #1a1a1a;
    --accent-soft: #7c8bff;
    --danger: #ff6b6b;
    --success: #4ade80;
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
    --shadow: 0 4px 16px rgba(0,0,0,0.4);
    --shadow-lg: 0 8px 32px rgba(0,0,0,0.5);
}
html[dir="rtl"] { --font: var(--font-fa); }

* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; }
body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    line-height: 1.4;
    -webkit-font-smoothing: antialiased;
    transition: background-color 0.2s, color 0.2s;
}
button {
    font-family: inherit;
    font-size: inherit;
    color: inherit;
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
}
input, textarea {
    font-family: inherit;
    font-size: inherit;
    color: inherit;
}
[hidden] { display: none !important; }

/* ============================================================================
   LAYOUT
   ========================================================================= */
#app {
    display: flex;
    flex-direction: column;
    height: 100vh;
    width: 100vw;
}
#main {
    flex: 1;
    display: flex;
    min-height: 0;
    overflow: hidden;
}

/* ============================================================================
   TOP BAR
   ========================================================================= */
#topbar {
    height: 48px;
    background: var(--panel);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    padding: 0 12px;
    gap: 8px;
    flex-shrink: 0;
    transition: background-color 0.2s, border-color 0.2s;
}
#topbar .tb-group { display: flex; align-items: center; gap: 4px; }
#topbar .tb-spacer { flex: 1; }
.tb-btn {
    height: 32px;
    min-width: 32px;
    padding: 0 10px;
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    color: var(--text);
    transition: background var(--transition), opacity var(--transition);
    font-size: 12.5px;
    font-weight: 500;
}
.tb-btn:hover { background: var(--panel-hover); }
.tb-btn:active { transform: scale(0.97); }
.tb-btn:disabled { opacity: 0.35; cursor: default; }
.tb-btn:disabled:hover { background: none; }
.tb-btn svg { width: 16px; height: 16px; }

#board-title {
    font-weight: 600;
    font-size: 14px;
    padding: 4px 8px;
    border-radius: var(--radius-sm);
    min-width: 60px;
    outline: none;
    border: 1px solid transparent;
    transition: border-color var(--transition), background var(--transition);
    color: var(--text);
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
#board-title:hover { background: var(--panel-hover); }
#board-title:focus { background: var(--bg); border-color: var(--accent-soft); }

#save-status {
    font-size: 12px;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 80px;
    padding: 0 6px;
}
#save-status.saving { color: var(--accent-soft); }
#save-status.error { color: var(--danger); }
#save-status.saved { color: var(--success); }
#save-status .spinner {
    width: 11px; height: 11px;
    border: 2px solid currentColor;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

.toggle-switch {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 10px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    color: var(--text-muted);
    cursor: pointer;
    transition: background var(--transition);
    user-select: none;
}
.toggle-switch:hover { background: var(--panel-hover); }
.toggle-switch .sw {
    width: 26px; height: 15px;
    border-radius: 999px;
    background: var(--border-strong);
    position: relative;
    transition: background var(--transition);
}
.toggle-switch .sw::after {
    content: '';
    position: absolute;
    top: 2px; left: 2px;
    width: 11px; height: 11px;
    border-radius: 50%;
    background: #fff;
    transition: transform var(--transition);
}
.toggle-switch.on .sw { background: var(--success); }
.toggle-switch.on .sw::after { transform: translateX(11px); }
html[dir="rtl"] .toggle-switch .sw::after { left: auto; right: 2px; }
html[dir="rtl"] .toggle-switch.on .sw::after { transform: translateX(-11px); }

/* ============================================================================
   SIDEBARS
   ========================================================================= */
.sidebar {
    width: 220px;
    background: var(--panel);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    transition: width var(--transition), background-color 0.2s;
    overflow: hidden;
}
#right-sidebar { border-right: none; border-left: 1px solid var(--border); }
html[dir="rtl"] .sidebar { border-right: none; border-left: 1px solid var(--border); }
html[dir="rtl"] #right-sidebar { border-left: none; border-right: 1px solid var(--border); }
.sidebar.collapsed { width: 0; border-width: 0; }
.sidebar-header {
    height: 40px;
    display: flex;
    align-items: center;
    padding: 0 12px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    justify-content: space-between;
}
.sidebar-body {
    flex: 1;
    overflow-y: auto;
    padding: 6px;
    min-height: 0;
}
.sidebar-body::-webkit-scrollbar { width: 8px; }
.sidebar-body::-webkit-scrollbar-thumb { background: var(--border-strong); border-radius: 4px; }
.sidebar-body::-webkit-scrollbar-track { background: transparent; }

#new-board-btn {
    width: 100%;
    height: 34px;
    background: var(--accent-soft);
    color: #fff;
    border-radius: var(--radius-sm);
    font-weight: 500;
    font-size: 12.5px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    margin-bottom: 6px;
    transition: opacity var(--transition), transform var(--transition);
}
#new-board-btn:hover { opacity: 0.9; }
#new-board-btn:active { transform: scale(0.98); }
#new-board-btn svg { width: 14px; height: 14px; }

.board-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 7px 8px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition);
    margin-bottom: 2px;
    position: relative;
}
.board-item:hover { background: var(--panel-hover); }
.board-item.active { background: var(--panel-2); }
.board-item.active::before {
    content: '';
    position: absolute;
    left: 0; top: 6px; bottom: 6px;
    width: 2px;
    background: var(--accent-soft);
    border-radius: 1px;
}
html[dir="rtl"] .board-item.active::before { left: auto; right: 0; }
.board-item .b-title {
    flex: 1;
    font-size: 12.5px;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--text);
}
.board-item .b-del {
    width: 20px; height: 20px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity var(--transition), background var(--transition);
    color: var(--text-muted);
}
.board-item:hover .b-del { opacity: 1; }
.board-item .b-del:hover { background: rgba(217,59,59,0.15); color: var(--danger); }
.board-item .b-del svg { width: 12px; height: 12px; }
.board-item .b-date {
    font-size: 10.5px;
    color: var(--text-subtle);
    white-space: nowrap;
}

/* ============================================================================
   LAYERS PANEL
   ========================================================================= */
.layer-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 6px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: background var(--transition);
    margin-bottom: 1px;
    user-select: none;
}
.layer-item:hover { background: var(--panel-hover); }
.layer-item.selected { background: var(--panel-2); }
.layer-item.dragging { opacity: 0.4; }
.layer-item.drag-over { box-shadow: inset 0 2px 0 var(--accent-soft); }
.layer-item .l-eye {
    width: 18px; height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 3px;
    color: var(--text-muted);
    opacity: 0.6;
    flex-shrink: 0;
}
.layer-item .l-eye:hover { opacity: 1; background: var(--panel-2); }
.layer-item .l-eye svg { width: 12px; height: 12px; }
.layer-item .l-eye.hidden-el { opacity: 0.3; }
.layer-item .l-icon {
    width: 18px; height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    flex-shrink: 0;
}
.layer-item .l-icon svg { width: 12px; height: 12px; }
.layer-item .l-name {
    flex: 1;
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.layer-item .l-name-input {
    flex: 1;
    font-size: 12px;
    background: var(--bg);
    border: 1px solid var(--accent-soft);
    border-radius: 3px;
    padding: 1px 4px;
    outline: none;
    min-width: 0;
}
.layer-item.hidden-element { opacity: 0.5; }

/* ============================================================================
   CANVAS
   ========================================================================= */
#canvas-wrap {
    flex: 1;
    position: relative;
    overflow: hidden;
    background: var(--bg);
    min-width: 0;
    touch-action: none;
    transition: background-color 0.2s;
}
#canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    display: block;
    cursor: default;
    touch-action: none;
}
#canvas.tool-text,
#canvas.tool-rectangle,
#canvas.tool-circle,
#canvas.tool-arrow { cursor: crosshair; }
#canvas.panning { cursor: grabbing !important; }
#canvas.can-pan { cursor: grab; }

#empty-hint {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: var(--text-subtle);
    font-size: 14px;
    text-align: center;
    pointer-events: none;
    user-select: none;
    transition: opacity 0.2s;
}

#zoom-indicator {
    position: absolute;
    right: 12px;
    bottom: 12px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius-full);
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 3px;
    box-shadow: var(--shadow-sm);
    z-index: 20;
    transition: background-color 0.2s, border-color 0.2s;
}
#zoom-indicator button {
    width: 24px; height: 24px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    font-size: 14px;
    transition: background var(--transition);
}
#zoom-indicator button:hover { background: var(--panel-hover); color: var(--text); }
#zoom-percent {
    font-size: 11.5px;
    font-weight: 500;
    padding: 0 6px;
    min-width: 42px;
    text-align: center;
    color: var(--text);
    font-variant-numeric: tabular-nums;
    cursor: pointer;
}

/* ============================================================================
   TOOL PILL
   ========================================================================= */
#tool-pill {
    position: absolute;
    bottom: 16px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 5px;
    display: flex;
    gap: 2px;
    box-shadow: var(--shadow);
    z-index: 20;
    transition: background-color 0.2s, border-color 0.2s;
}
.tool-btn {
    width: 40px;
    height: 40px;
    border-radius: var(--radius-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-muted);
    position: relative;
    transition: background var(--transition), color var(--transition);
}
.tool-btn:hover { background: var(--panel-hover); color: var(--text); }
.tool-btn.active { background: var(--accent-soft); color: #fff; }
.tool-btn svg { width: 18px; height: 18px; }
.tool-btn .kbd {
    position: absolute;
    bottom: 2px;
    right: 2px;
    font-size: 8.5px;
    opacity: 0.55;
    font-weight: 600;
    pointer-events: none;
}
.tool-btn.active .kbd { opacity: 0.8; }

.tool-divider {
    width: 1px;
    background: var(--border);
    margin: 6px 2px;
}

/* ============================================================================
   ELEMENT HIT + SELECTION
   ========================================================================= */
.el-hit { pointer-events: visiblePainted; cursor: move; }
[data-hidden="true"] { display: none; }
.selection-handle {
    fill: var(--panel);
    stroke: var(--accent-soft);
    stroke-width: 1.5;
    cursor: pointer;
}
.selection-handle:hover { fill: var(--accent-soft); }
.selection-outline {
    fill: none;
    stroke: var(--accent-soft);
    stroke-width: 1.5;
    pointer-events: none;
}
.selection-outline.dashed { stroke-dasharray: 4 3; }
.arrow-handle {
    fill: var(--panel);
    stroke: var(--accent-soft);
    stroke-width: 1.5;
    cursor: grab;
}
.arrow-handle:hover { fill: var(--accent-soft); }

/* ============================================================================
   PROPERTIES POPUP
   ========================================================================= */
#properties-popup {
    position: fixed;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    padding: 8px;
    z-index: 40;
    display: none;
    min-width: 200px;
    max-width: 280px;
    transition: background-color 0.2s, border-color 0.2s;
}
#properties-popup.visible { display: block; }
.prop-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
    min-height: 26px;
}
.prop-row:last-child { margin-bottom: 0; }
.prop-label {
    font-size: 11px;
    color: var(--text-muted);
    min-width: 52px;
    flex-shrink: 0;
}
.prop-input {
    height: 26px;
    padding: 0 6px;
    border: 1px solid var(--border);
    border-radius: 5px;
    background: var(--bg);
    color: var(--text);
    font-size: 12px;
    outline: none;
    transition: border-color var(--transition);
    min-width: 0;
    flex: 1;
}
.prop-input:focus { border-color: var(--accent-soft); }
.prop-input[type="number"] { max-width: 64px; }
.prop-input[type="color"] {
    padding: 2px;
    width: 32px;
    height: 26px;
    cursor: pointer;
    flex: 0 0 auto;
}
.prop-btn {
    height: 26px;
    padding: 0 8px;
    border-radius: 5px;
    background: var(--bg);
    border: 1px solid var(--border);
    font-size: 11px;
    color: var(--text);
    transition: background var(--transition), border-color var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
}
.prop-btn:hover { background: var(--panel-hover); }
.prop-btn.active { background: var(--accent-soft); color: #fff; border-color: var(--accent-soft); }
.prop-btn.danger { color: var(--danger); }
.prop-btn.danger:hover { background: rgba(217,59,59,0.1); }
.prop-btn svg { width: 12px; height: 12px; }
.prop-group { display: flex; gap: 3px; }
.prop-divider { height: 1px; background: var(--border); margin: 6px 0; }

/* ============================================================================
   TEXT EDIT OVERLAY
   ========================================================================= */
.text-edit-overlay {
    position: absolute;
    outline: 2px solid var(--accent-soft);
    background: transparent;
    white-space: pre-wrap;
    word-break: break-word;
    overflow-wrap: break-word;
    padding: 2px;
    z-index: 30;
    min-height: 20px;
    caret-color: var(--accent-soft);
    font-family: var(--font);
    line-height: 1.3;
}

/* ============================================================================
   TOASTS
   ========================================================================= */
#toast-container {
    position: fixed;
    bottom: 80px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: column;
    gap: 6px;
    z-index: 100;
    pointer-events: none;
    align-items: center;
}
.toast {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px 14px;
    box-shadow: var(--shadow-lg);
    font-size: 12.5px;
    display: flex;
    align-items: center;
    gap: 8px;
    pointer-events: auto;
    animation: toastIn 0.18s ease-out;
    max-width: 380px;
}
.toast.error { border-color: var(--danger); color: var(--danger); }
.toast.success { border-color: var(--success); color: var(--success); }
.toast button {
    font-weight: 600;
    text-decoration: underline;
    color: inherit;
    font-size: 12px;
}
@keyframes toastIn {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

/* ============================================================================
   DIALOGS
   ========================================================================= */
.dialog-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    z-index: 90;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.15s;
}
.dialog-backdrop.visible { display: flex; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.dialog {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    padding: 20px;
    min-width: 340px;
    max-width: 460px;
    width: 100%;
}
.dialog h3 {
    font-size: 15px;
    font-weight: 600;
    margin-bottom: 12px;
    color: var(--text);
}
.dialog p {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 16px;
    line-height: 1.5;
}
.dialog-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 16px;
}
.dialog-btn {
    height: 34px;
    padding: 0 16px;
    border-radius: var(--radius-sm);
    font-size: 13px;
    font-weight: 500;
    transition: opacity var(--transition), background var(--transition);
    background: var(--panel-2);
    color: var(--text);
}
.dialog-btn:hover { opacity: 0.85; }
.dialog-btn.primary { background: var(--accent-soft); color: #fff; }
.dialog-btn.danger { background: var(--danger); color: #fff; }

.radio-option {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    cursor: pointer;
    margin-bottom: 8px;
    transition: border-color var(--transition), background var(--transition);
}
.radio-option:hover { background: var(--panel-2); }
.radio-option.selected { border-color: var(--accent-soft); background: var(--panel-2); }
.radio-option input[type="radio"] { margin-top: 2px; accent-color: var(--accent-soft); }
.radio-option .ro-title { font-size: 13px; font-weight: 500; color: var(--text); }
.radio-option .ro-desc { font-size: 11.5px; color: var(--text-muted); margin-top: 2px; }
.radio-option .ro-size { font-size: 11px; color: var(--text-subtle); margin-top: 2px; }

/* ============================================================================
   MIN WIDTH WARNING
   ========================================================================= */
#width-warning {
    position: fixed;
    top: 60px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--panel);
    border: 1px solid var(--danger);
    color: var(--danger);
    padding: 8px 14px;
    border-radius: var(--radius);
    font-size: 12px;
    z-index: 200;
    box-shadow: var(--shadow);
    display: none;
}

/* ============================================================================
   UTIL
   ========================================================================= */
.visually-hidden {
    position: absolute;
    width: 1px; height: 1px;
    overflow: hidden;
    clip: rect(0,0,0,0);
}
body.no-select { user-select: none; }
body.dragging * { cursor: inherit !important; }
</style>
</head>
<body>
<div id="app">

    <!-- TOP BAR -->
    <header id="topbar">
        <div class="tb-group">
            <button class="tb-btn" id="toggle-left" aria-label="Toggle boards panel" title="Toggle boards panel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="9" y1="4" x2="9" y2="20"/></svg>
            </button>
            <div id="board-title" contenteditable="true" spellcheck="false" role="textbox" aria-label="Board title"></div>
        </div>

        <div class="tb-group" style="margin-inline-start: 8px;">
            <button class="tb-btn" id="btn-undo" aria-label="Undo" title="Undo (Ctrl+Z)" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg>
            </button>
            <button class="tb-btn" id="btn-redo" aria-label="Redo" title="Redo (Ctrl+Shift+Z)" disabled>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 14l5-5-5-5"/><path d="M20 9H9a5 5 0 0 0 0 10h3"/></svg>
            </button>
        </div>

        <div id="save-status" aria-live="polite"></div>

        <div class="tb-spacer"></div>

        <div class="tb-group">
            <button class="toggle-switch on" id="toggle-autosave" title="Toggle auto-save">
                <span class="sw"></span>
                <span data-i18n="autosave">Auto-save</span>
            </button>
            <button class="tb-btn" id="btn-save" title="Save (Ctrl+S)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                <span data-i18n="save">Save</span>
            </button>
            <button class="tb-btn" id="btn-export" title="Export JSON">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <span data-i18n="export">Export</span>
            </button>
        </div>

        <div class="tb-group" style="margin-inline-start: 4px;">
            <button class="tb-btn" id="btn-lang" title="Toggle language">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <span id="lang-label">EN</span>
            </button>
            <button class="tb-btn" id="btn-theme" title="Toggle theme">
                <svg id="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                <svg id="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" hidden><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <button class="tb-btn" id="toggle-right" aria-label="Toggle layers panel" title="Toggle layers panel">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><line x1="15" y1="4" x2="15" y2="20"/></svg>
            </button>
        </div>
    </header>

    <!-- MAIN -->
    <div id="main">
        <aside id="left-sidebar" class="sidebar">
            <div class="sidebar-header">
                <span data-i18n="boards">Boards</span>
            </div>
            <div class="sidebar-body">
                <button id="new-board-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span data-i18n="newBoard">New Board</span>
                </button>
                <div id="board-list"></div>
            </div>
        </aside>

        <main id="canvas-wrap">
            <svg id="canvas" xmlns="http://www.w3.org/2000/svg">
                <g id="world">
                    <g id="elements"></g>
                    <g id="preview"></g>
                </g>
                <g id="selection-overlay"></g>
            </svg>
            <div id="empty-hint" data-i18n="emptyHint">Pick a tool from the bottom to start</div>

            <div id="zoom-indicator">
                <button id="zoom-out" title="Zoom out" aria-label="Zoom out">−</button>
                <span id="zoom-percent" title="Reset zoom">100%</span>
                <button id="zoom-in" title="Zoom in" aria-label="Zoom in">+</button>
            </div>

            <div id="tool-pill" role="toolbar" aria-label="Tools">
                <button class="tool-btn active" data-tool="select" title="Select (V)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 3l7 17 2-7 7-2z"/></svg>
                    <span class="kbd">V</span>
                </button>
                <button class="tool-btn" data-tool="text" title="Text (T)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                    <span class="kbd">T</span>
                </button>
                <button class="tool-btn" data-tool="rectangle" title="Rectangle (R)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="1.5"/></svg>
                    <span class="kbd">R</span>
                </button>
                <button class="tool-btn" data-tool="circle" title="Circle (O)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="12" rx="8" ry="7"/></svg>
                    <span class="kbd">O</span>
                </button>
                <button class="tool-btn" data-tool="arrow" title="Arrow (A)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="19" x2="19" y2="5"/><polyline points="12 5 19 5 19 12"/></svg>
                    <span class="kbd">A</span>
                </button>
                <div class="tool-divider"></div>
                <button class="tool-btn" data-tool="image" title="Insert image">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </button>
            </div>
        </main>

        <aside id="right-sidebar" class="sidebar">
            <div class="sidebar-header">
                <span data-i18n="layers">Layers</span>
            </div>
            <div class="sidebar-body" id="layer-list"></div>
        </aside>
    </div>
</div>

<!-- PROPERTIES POPUP -->
<div id="properties-popup" role="dialog" aria-label="Element properties"></div>

<!-- EXPORT DIALOG -->
<div class="dialog-backdrop" id="export-dialog">
    <div class="dialog">
        <h3 data-i18n="exportTitle">Export Board as JSON</h3>
        <p data-i18n="exportDesc">Choose how to handle images in the exported file.</p>
        <label class="radio-option selected" data-export-mode="embed">
            <input type="radio" name="export-mode" value="embed" checked>
            <div>
                <div class="ro-title" data-i18n="exportEmbed">Embed images (base64, compressed)</div>
                <div class="ro-desc" data-i18n="exportEmbedDesc">Max 800px, JPEG quality 0.7. Self-contained file.</div>
                <div class="ro-size" id="size-embed"></div>
            </div>
        </label>
        <label class="radio-option" data-export-mode="link">
            <input type="radio" name="export-mode" value="link">
            <div>
                <div class="ro-title" data-i18n="exportLink">Image metadata only</div>
                <div class="ro-desc" data-i18n="exportLinkDesc">Sets src to null, keeps position/size/alt.</div>
                <div class="ro-size" id="size-link"></div>
            </div>
        </label>
        <div class="dialog-actions">
            <button class="dialog-btn" id="export-cancel" data-i18n="cancel">Cancel</button>
            <button class="dialog-btn primary" id="export-confirm" data-i18n="download">Download</button>
        </div>
    </div>
</div>

<!-- CONFIRM DIALOG -->
<div class="dialog-backdrop" id="confirm-dialog">
    <div class="dialog">
        <h3 id="confirm-title">Confirm</h3>
        <p id="confirm-message"></p>
        <div class="dialog-actions">
            <button class="dialog-btn" id="confirm-cancel" data-i18n="cancel">Cancel</button>
            <button class="dialog-btn danger" id="confirm-ok" data-i18n="delete">Delete</button>
        </div>
    </div>
</div>

<div id="toast-container"></div>
<div id="width-warning">⚠ Recommended minimum viewport width: 1024px</div>

<script>
/* ============================================================================
   APP NAMESPACE
   ========================================================================= */
window.App = (function () {
    'use strict';

    // ========================================================================
    // CONFIG
    // ========================================================================
    const CONFIG = {
        MIN_ZOOM: 0.1,
        MAX_ZOOM: 4,
        ZOOM_STEP: 1.2,
        AUTOSAVE_DELAY: 1500,
        HISTORY_LIMIT: 50,
        MIN_ELEMENT_SIZE: 10,
        PASTE_OFFSET: 20,
        HANDLE_SIZE: 5,
        ARROW_HIT_WIDTH: 14,
        DRAG_THRESHOLD: 3,
        EXPORT_MAX_DIM: 800,
        EXPORT_QUALITY: 0.7,
        UPLOAD_MAX_DIM: 1600,
        UPLOAD_QUALITY: 0.85,
        TEXT_MIN_SIZE: 12,
    };

    // ========================================================================
    // STATE
    // ========================================================================
    const S = {
        // Board data
        board: null,
        boards: [],

        // UI state
        tool: 'select',
        selection: new Set(),
        zoom: 1,
        panX: 0,
        panY: 0,
        theme: 'light',
        lang: 'en',

        // Save
        autoSave: true,
        savedSnapshot: '',
        saveTimer: null,
        isSaving: false,
        pendingSave: false,
        dirty: false,

        // History
        history: [],
        redoStack: [],

        // Clipboard
        clipboard: null,

        // Interaction
        drag: null,          // active drag state
        spaceDown: false,    // pan modifier
        editingId: null,     // currently text-editing element
        propSnapshot: null,  // snapshot before property edit
        dragSrcLayerId: null,// layer reorder

        // Render throttling
        renderPending: false,
        rafId: null,

        // Cache
        imageNodeCache: new Map(),
    };

    // ========================================================================
    // DOM REFS
    // ========================================================================
    const D = {};
    function cacheDom() {
        D.app = document.getElementById('app');
        D.topbar = document.getElementById('topbar');
        D.boardTitle = document.getElementById('board-title');
        D.saveStatus = document.getElementById('save-status');
        D.btnUndo = document.getElementById('btn-undo');
        D.btnRedo = document.getElementById('btn-redo');
        D.btnSave = document.getElementById('btn-save');
        D.btnExport = document.getElementById('btn-export');
        D.btnTheme = document.getElementById('btn-theme');
        D.btnLang = document.getElementById('btn-lang');
        D.langLabel = document.getElementById('lang-label');
        D.themeSun = document.getElementById('theme-icon-sun');
        D.themeMoon = document.getElementById('theme-icon-moon');
        D.toggleAutoSave = document.getElementById('toggle-autosave');
        D.toggleLeft = document.getElementById('toggle-left');
        D.toggleRight = document.getElementById('toggle-right');
        D.leftSidebar = document.getElementById('left-sidebar');
        D.rightSidebar = document.getElementById('right-sidebar');
        D.boardList = document.getElementById('board-list');
        D.newBoardBtn = document.getElementById('new-board-btn');
        D.canvasWrap = document.getElementById('canvas-wrap');
        D.canvas = document.getElementById('canvas');
        D.world = document.getElementById('world');
        D.elementsG = document.getElementById('elements');
        D.previewG = document.getElementById('preview');
        D.selectionG = document.getElementById('selection-overlay');
        D.emptyHint = document.getElementById('empty-hint');
        D.zoomPercent = document.getElementById('zoom-percent');
        D.zoomIn = document.getElementById('zoom-in');
        D.zoomOut = document.getElementById('zoom-out');
        D.toolPill = document.getElementById('tool-pill');
        D.layerList = document.getElementById('layer-list');
        D.props = document.getElementById('properties-popup');
        D.toastContainer = document.getElementById('toast-container');
        D.exportDialog = document.getElementById('export-dialog');
        D.sizeEmbed = document.getElementById('size-embed');
        D.sizeLink = document.getElementById('size-link');
        D.exportConfirm = document.getElementById('export-confirm');
        D.exportCancel = document.getElementById('export-cancel');
        D.confirmDialog = document.getElementById('confirm-dialog');
        D.confirmTitle = document.getElementById('confirm-title');
        D.confirmMessage = document.getElementById('confirm-message');
        D.confirmOk = document.getElementById('confirm-ok');
        D.confirmCancel = document.getElementById('confirm-cancel');
        D.widthWarning = document.getElementById('width-warning');
    }

    // ========================================================================
    // UTILS
    // ========================================================================
    function uid(prefix) {
        return (prefix || 'e') + '_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
    }
    function clone(obj) { return JSON.parse(JSON.stringify(obj)); }
    function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
    function deepEqual(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    }
    function slugify(s) {
        return (s || 'board').toString().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'board';
    }
    function dateStamp(d) {
        const x = d || new Date();
        return x.getFullYear().toString() +
            String(x.getMonth() + 1).padStart(2, '0') +
            String(x.getDate()).padStart(2, '0');
    }
    function fmtSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    // ========================================================================
    // GEOMETRY / COORDINATES
    // ========================================================================
    function canvasRect() {
        return D.canvas.getBoundingClientRect();
    }
    function screenToWorld(clientX, clientY) {
        const r = canvasRect();
        return {
            x: (clientX - r.left - S.panX) / S.zoom,
            y: (clientY - r.top - S.panY) / S.zoom,
        };
    }
    function worldToScreen(wx, wy) {
        const r = canvasRect();
        return {
            x: wx * S.zoom + S.panX + r.left,
            y: wy * S.zoom + S.panY + r.top,
        };
    }
    function worldToCanvas(wx, wy) {
        // world → canvas-relative pixel (not client)
        return { x: wx * S.zoom + S.panX, y: wy * S.zoom + S.panY };
    }

    function getElement(id) {
        if (!S.board) return null;
        return S.board.layers.find(e => e.id === id) || null;
    }
    function getSelected() {
        if (!S.board) return [];
        return S.board.layers.filter(e => S.selection.has(e.id));
    }
    function elementBounds(el) {
        if (!el) return null;
        if (el.type === 'arrow') {
            return {
                x: Math.min(el.x1, el.x2),
                y: Math.min(el.y1, el.y2),
                w: Math.abs(el.x2 - el.x1),
                h: Math.abs(el.y2 - el.y1),
            };
        }
        return { x: el.x, y: el.y, w: el.w, h: el.h };
    }
    function selectionBounds() {
        const els = getSelected();
        if (!els.length) return null;
        let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
        for (const el of els) {
            const b = elementBounds(el);
            minX = Math.min(minX, b.x);
            minY = Math.min(minY, b.y);
            maxX = Math.max(maxX, b.x + b.w);
            maxY = Math.max(maxY, b.y + b.h);
        }
        return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
    }
    function normalizeZ() {
        if (!S.board) return;
        const sorted = [...S.board.layers].sort((a, b) => a.zIndex - b.zIndex);
        sorted.forEach((el, i) => { el.zIndex = i; });
    }

    // ========================================================================
    // API
    // ========================================================================
    const API = {
        async list() {
            const r = await fetch('?action=list');
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'list failed');
            return j.data;
        },
        async create() {
            const r = await fetch('?action=create', { method: 'POST' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'create failed');
            return j.data.id;
        },
        async load(id) {
            const r = await fetch('?action=load&id=' + encodeURIComponent(id));
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'load failed');
            return j.data;
        },
        async save(id, board) {
            const r = await fetch('?action=save&id=' + encodeURIComponent(id), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(board),
            });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'save failed');
            return j.data;
        },
        async remove(id) {
            const r = await fetch('?action=delete&id=' + encodeURIComponent(id), { method: 'POST' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'delete failed');
            return j.data;
        },
        async upload(file) {
            const fd = new FormData();
            fd.append('file', file);
            const r = await fetch('?action=upload', { method: 'POST', body: fd });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'upload failed');
            return j.data.url;
        },
    };

    // ========================================================================
    // I18N
    // ========================================================================
    const I18N = {
        en: {
            boards: 'Boards',
            layers: 'Layers',
            newBoard: 'New Board',
            save: 'Save',
            export: 'Export',
            autosave: 'Auto-save',
            emptyHint: 'Pick a tool from the bottom to start',
            untitled: 'Untitled Board',
            saved: 'Saved',
            saving: 'Saving…',
            unsaved: 'Unsaved',
            saveError: 'Save failed',
            retry: 'Retry',
            confirmDelete: 'Delete board?',
            confirmDeleteMsg: 'This will permanently delete "{name}". This cannot be undone.',
            cancel: 'Cancel',
            delete: 'Delete',
            exportTitle: 'Export Board as JSON',
            exportDesc: 'Choose how to handle images in the exported file.',
            exportEmbed: 'Embed images (base64, compressed)',
            exportEmbedDesc: 'Max 800px, JPEG quality 0.7. Self-contained file.',
            exportLink: 'Image metadata only',
            exportLinkDesc: 'Sets src to null, keeps position/size/alt.',
            download: 'Download',
            computing: 'Computing…',
            boardCreated: 'Board created',
            boardDeleted: 'Board deleted',
            boardNotFound: 'Board not found',
            boardCorrupt: 'Board file is corrupted',
            exportSuccess: 'Exported',
            exportError: 'Export failed',
            uploadError: 'Image upload failed',
            noFill: 'No fill',
            fill: 'Fill',
            border: 'Border',
            radius: 'Radius',
            width: 'Width',
            color: 'Color',
            size: 'Size',
            textAlign: 'Align',
            bold: 'Bold',
            italic: 'Italic',
            replace: 'Replace',
            left: 'Left',
            center: 'Center',
            right: 'Right',
            properties: 'Properties',
            visible: 'Visible',
            hidden: 'Hidden',
            select: 'Select',
            text: 'Text',
            rectangle: 'Rectangle',
            circle: 'Circle',
            arrow: 'Arrow',
            image: 'Image',
            rename: 'Rename',
            deleteImage: 'Delete',
            tool: 'Tool',
            pasteImage: 'Image pasted',
            zoom: 'Zoom',
        },
        fa: {
            boards: 'بوردها',
            layers: 'لایه‌ها',
            newBoard: 'بورد جدید',
            save: 'ذخیره',
            export: 'خروجی',
            autosave: 'ذخیره خودکار',
            emptyHint: 'برای شروع یک ابزار از پایین انتخاب کنید',
            untitled: 'بورد بدون نام',
            saved: 'ذخیره شد',
            saving: 'در حال ذخیره…',
            unsaved: 'ذخیره نشده',
            saveError: 'ذخیره نشد',
            retry: 'تلاش مجدد',
            confirmDelete: 'حذف بورد؟',
            confirmDeleteMsg: '«{name}» برای همیشه حذف می‌شود. این عمل قابل بازگشت نیست.',
            cancel: 'لغو',
            delete: 'حذف',
            exportTitle: 'خروجی JSON از بورد',
            exportDesc: 'نحوه برخورد با تصاویر در فایل خروجی را انتخاب کنید.',
            exportEmbed: 'جاسازی تصاویر (base64، فشرده)',
            exportEmbedDesc: 'حداکثر ۸۰۰ پیکسل، کیفیت JPEG ۰.۷. فایل خودکفا.',
            exportLink: 'فقط متادیتای تصویر',
            exportLinkDesc: 'src برابر null، موقعیت/اندازه/alt حفظ می‌شود.',
            download: 'دانلود',
            computing: 'در حال محاسبه…',
            boardCreated: 'بورد ساخته شد',
            boardDeleted: 'بورد حذف شد',
            boardNotFound: 'بورد یافت نشد',
            boardCorrupt: 'فایل بورد خراب است',
            exportSuccess: 'خروجی گرفته شد',
            exportError: 'خروجی ناموفق',
            uploadError: 'آپلود تصویر ناموفق',
            noFill: 'بدون پر',
            fill: 'پر',
            border: 'حاشیه',
            radius: 'شعاع',
            width: 'عرض',
            color: 'رنگ',
            size: 'اندازه',
            textAlign: 'چینش',
            bold: 'ضخیم',
            italic: 'مورب',
            replace: 'تعویض',
            left: 'چپ',
            center: 'وسط',
            right: 'راست',
            properties: 'ویژگی‌ها',
            visible: 'قابل مشاهده',
            hidden: 'پنهان',
            select: 'انتخاب',
            text: 'متن',
            rectangle: 'مستطیل',
            circle: 'دایره',
            arrow: 'فلش',
            image: 'تصویر',
            rename: 'تغییر نام',
            deleteImage: 'حذف',
            tool: 'ابزار',
            pasteImage: 'تصویر جای‌گذاری شد',
            zoom: 'بزرگ‌نمایی',
        },
    };
    function t(key) {
        return (I18N[S.lang] && I18N[S.lang][key]) || I18N.en[key] || key;
    }
    function applyLang() {
        document.documentElement.lang = S.lang;
        document.documentElement.dir = S.lang === 'fa' ? 'rtl' : 'ltr';
        D.langLabel.textContent = S.lang.toUpperCase();
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const k = el.getAttribute('data-i18n');
            const v = t(k);
            if (v) el.textContent = v;
        });
        document.title = (S.board ? S.board.title : 'Board Editor') + ' — ' + (S.lang === 'fa' ? 'ویرایشگر' : 'Editor');
        renderLayers();
        renderProperties();
    }

    // ========================================================================
    // THEME
    // ========================================================================
    function applyTheme() {
        document.documentElement.setAttribute('data-theme', S.theme);
        if (S.theme === 'dark') {
            D.themeSun.hidden = true;
            D.themeMoon.hidden = false;
        } else {
            D.themeSun.hidden = false;
            D.themeMoon.hidden = true;
        }
    }

    // ========================================================================
    // TOAST / CONFIRM
    // ========================================================================
    function toast(msg, type, actionLabel, actionFn) {
        const el = document.createElement('div');
        el.className = 'toast' + (type ? ' ' + type : '');
        const span = document.createElement('span');
        span.textContent = msg;
        el.appendChild(span);
        if (actionLabel && actionFn) {
            const btn = document.createElement('button');
            btn.textContent = actionLabel;
            btn.addEventListener('click', () => { actionFn(); el.remove(); });
            el.appendChild(btn);
        }
        D.toastContainer.appendChild(el);
        const ttl = type === 'error' ? 5000 : 3000;
        setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.2s'; setTimeout(() => el.remove(), 220); }, ttl);
    }

    function showConfirm(title, message, onOk, okLabel) {
        D.confirmTitle.textContent = title;
        D.confirmMessage.textContent = message;
        D.confirmOk.textContent = okLabel || t('delete');
        D.confirmDialog.classList.add('visible');
        const cleanup = () => {
            D.confirmDialog.classList.remove('visible');
            D.confirmOk.removeEventListener('click', okHandler);
            D.confirmCancel.removeEventListener('click', cancelHandler);
        };
        const okHandler = () => { cleanup(); onOk(); };
        const cancelHandler = () => { cleanup(); };
        D.confirmOk.addEventListener('click', okHandler);
        D.confirmCancel.addEventListener('click', cancelHandler);
    }

    // ========================================================================
    // HISTORY
    // ========================================================================
    function pushHistory() {
        if (!S.board) return;
        S.history.push(JSON.stringify(S.board.layers));
        if (S.history.length > CONFIG.HISTORY_LIMIT) S.history.shift();
        S.redoStack.length = 0;
        updateUndoRedoUI();
    }
    function undo() {
        if (!S.board || S.history.length === 0) return;
        S.redoStack.push(JSON.stringify(S.board.layers));
        S.board.layers = JSON.parse(S.history.pop());
        normalizeZ();
        pruneSelection();
        renderAll();
        scheduleSave();
        updateUndoRedoUI();
    }
    function redo() {
        if (!S.board || S.redoStack.length === 0) return;
        S.history.push(JSON.stringify(S.board.layers));
        S.board.layers = JSON.parse(S.redoStack.pop());
        normalizeZ();
        pruneSelection();
        renderAll();
        scheduleSave();
        updateUndoRedoUI();
    }
    function pruneSelection() {
        if (!S.board) { S.selection.clear(); return; }
        const ids = new Set(S.board.layers.map(e => e.id));
        for (const id of [...S.selection]) {
            if (!ids.has(id)) S.selection.delete(id);
        }
    }
    function updateUndoRedoUI() {
        D.btnUndo.disabled = !S.board || S.history.length === 0;
        D.btnRedo.disabled = !S.board || S.redoStack.length === 0;
    }

    // ========================================================================
    // SAVE / AUTOSAVE
    // ========================================================================
    function scheduleSave() {
        if (!S.board) return;
        S.dirty = true;
        updateSaveStatus('unsaved');
        if (!S.autoSave) return;
        clearTimeout(S.saveTimer);
        S.saveTimer = setTimeout(() => doSave(), CONFIG.AUTOSAVE_DELAY);
    }
    async function doSave() {
        if (!S.board) return;
        if (S.isSaving) { S.pendingSave = true; return; }
        const snapshot = JSON.stringify(S.board);
        if (snapshot === S.savedSnapshot && !S.dirty) return;
        S.isSaving = true;
        updateSaveStatus('saving');
        try {
            await API.save(S.board.id, S.board);
            S.savedSnapshot = snapshot;
            S.dirty = false;
            updateSaveStatus('saved');
        } catch (e) {
            console.error('save failed', e);
            updateSaveStatus('error');
            toast(t('saveError') + ': ' + e.message, 'error', t('retry'), () => doSave());
        } finally {
            S.isSaving = false;
            if (S.pendingSave) {
                S.pendingSave = false;
                doSave();
            }
        }
    }
    function updateSaveStatus(state) {
        const el = D.saveStatus;
        el.className = '';
        el.innerHTML = '';
        if (!S.board) { el.textContent = ''; return; }
        if (state === 'saving') {
            el.classList.add('saving');
            const sp = document.createElement('span');
            sp.className = 'spinner';
            el.appendChild(sp);
            el.appendChild(document.createTextNode(t('saving')));
        } else if (state === 'saved') {
            el.classList.add('saved');
            el.textContent = '✓ ' + t('saved');
        } else if (state === 'error') {
            el.classList.add('error');
            el.textContent = '⚠ ' + t('saveError');
        } else if (state === 'unsaved') {
            el.textContent = t('unsaved');
        }
    }

    // ========================================================================
    // RENDER (SVG)
    // ========================================================================
    function scheduleRender(full) {
        if (S.renderPending) return;
        S.renderPending = true;
        S.rafId = requestAnimationFrame(() => {
            S.renderPending = false;
            if (full === false) {
                renderElements();
                renderSelection();
            } else {
                renderAll();
            }
        });
    }

    function renderAll() {
        renderElements();
        renderSelection();
        renderLayers();
        renderProperties();
        updateTransform();
        updateEmptyHint();
        updateUndoRedoUI();
    }

    function updateTransform() {
        D.world.setAttribute('transform', `translate(${S.panX} ${S.panY}) scale(${S.zoom})`);
        D.zoomPercent.textContent = Math.round(S.zoom * 100) + '%';
        // Reposition selection handles (they live in screen space)
        renderSelection();
        // Reposition property popup
        positionProperties();
    }

    function updateEmptyHint() {
        const hasEls = S.board && S.board.layers.length > 0;
        D.emptyHint.style.opacity = hasEls ? '0' : '1';
    }

    function clearNode(node) { while (node.firstChild) node.removeChild(node.firstChild); }

    function renderElements() {
        clearNode(D.elementsG);
        if (!S.board) return;
        const sorted = [...S.board.layers].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0));
        for (const el of sorted) {
            if (el.hidden || el._editing) continue;
            const node = createElementNode(el);
            if (node) D.elementsG.appendChild(node);
        }
    }

    function svgEl(tag, attrs) {
        const n = document.createElementNS('http://www.w3.org/2000/svg', tag);
        if (attrs) for (const k in attrs) n.setAttribute(k, attrs[k]);
        return n;
    }

    function createElementNode(el) {
        if (el.type === 'rectangle') {
            const g = svgEl('g', { 'data-id': el.id, 'data-type': 'rectangle' });
            const r = svgEl('rect', {
                x: el.x, y: el.y, width: el.w, height: el.h,
                rx: (el.border && el.border.radius) || 0,
                ry: (el.border && el.border.radius) || 0,
                fill: (el.fill && el.fill !== 'none') ? el.fill : 'transparent',
                stroke: (el.border && el.border.width > 0 && el.border.color) ? el.border.color : 'none',
                'stroke-width': (el.border && el.border.width) || 0,
                'class': 'el-hit',
            });
            g.appendChild(r);
            if (el.text) {
                const fo = svgEl('foreignObject', {
                    x: el.x, y: el.y, width: el.w, height: el.h,
                    'pointer-events': 'none',
                });
                const div = document.createElement('div');
                div.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
                div.style.cssText = `width:100%;height:100%;display:flex;align-items:center;justify-content:center;text-align:${el.textAlign || 'center'};font-size:${el.fontSize || 16}px;color:${el.color || '#111'};font-weight:${el.fontWeight || 'normal'};font-style:${el.fontStyle || 'normal'};white-space:pre-wrap;word-break:break-word;padding:6px;overflow:hidden;box-sizing:border-box;`;
                div.textContent = el.text;
                fo.appendChild(div);
                g.appendChild(fo);
            }
            return g;
        }
        if (el.type === 'circle') {
            const g = svgEl('g', { 'data-id': el.id, 'data-type': 'circle' });
            const cx = el.x + el.w / 2;
            const cy = el.y + el.h / 2;
            const e = svgEl('ellipse', {
                cx, cy, rx: el.w / 2, ry: el.h / 2,
                fill: (el.fill && el.fill !== 'none') ? el.fill : 'transparent',
                stroke: (el.border && el.border.width > 0 && el.border.color) ? el.border.color : 'none',
                'stroke-width': (el.border && el.border.width) || 0,
                'class': 'el-hit',
            });
            g.appendChild(e);
            return g;
        }
        if (el.type === 'text') {
            const g = svgEl('g', { 'data-id': el.id, 'data-type': 'text' });
            // Transparent rect for hit testing
            const hit = svgEl('rect', {
                x: el.x, y: el.y, width: el.w, height: el.h,
                fill: 'transparent', 'class': 'el-hit',
            });
            g.appendChild(hit);
            const fo = svgEl('foreignObject', {
                x: el.x, y: el.y, width: el.w, height: el.h,
                'pointer-events': 'none',
            });
            const div = document.createElement('div');
            div.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
            div.style.cssText = `width:100%;height:100%;font-size:${el.fontSize || 18}px;font-weight:${el.fontWeight || 'normal'};font-style:${el.fontStyle || 'normal'};text-align:${el.textAlign || 'left'};color:${el.color || '#111'};white-space:pre-wrap;word-break:break-word;line-height:1.3;overflow:hidden;`;
            div.textContent = el.text || '';
            fo.appendChild(div);
            g.appendChild(fo);
            return g;
        }
        if (el.type === 'image') {
            const g = svgEl('g', { 'data-id': el.id, 'data-type': 'image' });
            if (el.src) {
                const img = svgEl('image', {
                    x: el.x, y: el.y, width: el.w, height: el.h,
                    href: el.src,
                    preserveAspectRatio: 'none',
                    'class': 'el-hit',
                });
                g.appendChild(img);
            } else {
                const r = svgEl('rect', {
                    x: el.x, y: el.y, width: el.w, height: el.h,
                    fill: 'rgba(120,120,120,0.15)', 'class': 'el-hit',
                });
                g.appendChild(r);
                if (el._uploading) {
                    const fo = svgEl('foreignObject', {
                        x: el.x, y: el.y, width: el.w, height: el.h,
                        'pointer-events': 'none',
                    });
                    const div = document.createElement('div');
                    div.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
                    div.style.cssText = 'width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:Inter,sans-serif;font-size:12px;color:#666;';
                    div.textContent = '⏳';
                    fo.appendChild(div);
                    g.appendChild(fo);
                }
            }
            return g;
        }
        if (el.type === 'arrow') {
            const g = svgEl('g', { 'data-id': el.id, 'data-type': 'arrow' });
            const color = (el.style && el.style.color) || '#333';
            const width = (el.style && el.style.width) || 2;
            // Hit area
            const hit = svgEl('line', {
                x1: el.x1, y1: el.y1, x2: el.x2, y2: el.y2,
                stroke: 'transparent', 'stroke-width': Math.max(CONFIG.ARROW_HIT_WIDTH, width),
                'stroke-linecap': 'round', 'pointer-events': 'stroke', 'class': 'el-hit',
            });
            g.appendChild(hit);
            const line = svgEl('line', {
                x1: el.x1, y1: el.y1, x2: el.x2, y2: el.y2,
                stroke: color, 'stroke-width': width, 'stroke-linecap': 'round',
                'pointer-events': 'none',
            });
            g.appendChild(line);
            // Arrowhead
            const angle = Math.atan2(el.y2 - el.y1, el.x2 - el.x1);
            const headLen = 8 + width * 2.2;
            const a1 = angle - Math.PI / 7;
            const a2 = angle + Math.PI / 7;
            const p1x = el.x2 - headLen * Math.cos(a1);
            const p1y = el.y2 - headLen * Math.sin(a1);
            const p2x = el.x2 - headLen * Math.cos(a2);
            const p2y = el.y2 - headLen * Math.sin(a2);
            const poly = svgEl('polygon', {
                points: `${el.x2},${el.y2} ${p1x},${p1y} ${p2x},${p2y}`,
                fill: color, 'pointer-events': 'none',
            });
            g.appendChild(poly);
            return g;
        }
        return null;
    }

    function renderSelection() {
        clearNode(D.selectionG);
        if (!S.board) return;
        const els = getSelected();
        if (!els.length) return;

        // Draw individual outlines
        for (const el of els) {
            const b = elementBounds(el);
            const p = worldToCanvas(b.x, b.y);
            const w = b.w * S.zoom;
            const h = b.h * S.zoom;
            const rect = svgEl('rect', {
                x: p.x, y: p.y, width: w, height: h,
                'class': 'selection-outline' + (els.length > 1 ? ' dashed' : ''),
                rx: 2,
            });
            D.selectionG.appendChild(rect);
        }

        if (els.length === 1 && els[0].type === 'arrow') {
            // Arrow endpoint handles
            const el = els[0];
            const p1 = worldToCanvas(el.x1, el.y1);
            const p2 = worldToCanvas(el.x2, el.y2);
            const h1 = svgEl('circle', {
                cx: p1.x, cy: p1.y, r: CONFIG.HANDLE_SIZE + 1,
                'class': 'arrow-handle', 'data-handle': 'arrow-start',
                style: 'pointer-events:all;',
            });
            const h2 = svgEl('circle', {
                cx: p2.x, cy: p2.y, r: CONFIG.HANDLE_SIZE + 1,
                'class': 'arrow-handle', 'data-handle': 'arrow-end',
                style: 'pointer-events:all;',
            });
            D.selectionG.appendChild(h1);
            D.selectionG.appendChild(h2);
            return;
        }

        // Bounding box with handles
        const b = selectionBounds();
        if (!b) return;
        const p = worldToCanvas(b.x, b.y);
        const w = b.w * S.zoom;
        const h = b.h * S.zoom;

        // Handles (8)
        const handles = [
            ['nw', p.x, p.y],
            ['n',  p.x + w / 2, p.y],
            ['ne', p.x + w, p.y],
            ['e',  p.x + w, p.y + h / 2],
            ['se', p.x + w, p.y + h],
            ['s',  p.x + w / 2, p.y + h],
            ['sw', p.x, p.y + h],
            ['w',  p.x, p.y + h / 2],
        ];
        for (const [dir, cx, cy] of handles) {
            const c = svgEl('circle', {
                cx, cy, r: CONFIG.HANDLE_SIZE,
                'class': 'selection-handle',
                'data-handle': dir,
                style: 'pointer-events:all;',
            });
            // Set cursor based on direction
            const cursors = { nw:'nwse-resize', n:'ns-resize', ne:'nesw-resize', e:'ew-resize', se:'nwse-resize', s:'ns-resize', sw:'nesw-resize', w:'ew-resize' };
            c.style.cursor = cursors[dir] || 'pointer';
            D.selectionG.appendChild(c);
        }
    }

    // ========================================================================
    // LAYERS PANEL
    // ========================================================================
    function renderLayers() {
        clearNode(D.layerList);
        if (!S.board) return;
        const sorted = [...S.board.layers].sort((a, b) => (b.zIndex || 0) - (a.zIndex || 0));
        for (const el of sorted) {
            D.layerList.appendChild(createLayerItem(el));
        }
    }
    function createLayerItem(el) {
        const item = document.createElement('div');
        item.className = 'layer-item' + (S.selection.has(el.id) ? ' selected' : '') + (el.hidden ? ' hidden-element' : '');
        item.dataset.id = el.id;
        item.draggable = true;

        // Eye toggle
        const eye = document.createElement('div');
        eye.className = 'l-eye' + (el.hidden ? ' hidden-el' : '');
        eye.title = t('visible');
        eye.innerHTML = el.hidden
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        eye.addEventListener('click', (e) => {
            e.stopPropagation();
            pushHistory();
            el.hidden = !el.hidden;
            renderElements();
            renderSelection();
            renderLayers();
            scheduleSave();
        });
        item.appendChild(eye);

        // Icon
        const icon = document.createElement('div');
        icon.className = 'l-icon';
        const icons = {
            text: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
            rectangle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="1.5"/></svg>',
            circle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="12" rx="8" ry="7"/></svg>',
            arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="19" x2="19" y2="5"/><polyline points="12 5 19 5 19 12"/></svg>',
            image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><polyline points="21 15 16 10 5 21"/></svg>',
        };
        icon.innerHTML = icons[el.type] || icons.rectangle;
        item.appendChild(icon);

        // Name
        const name = document.createElement('div');
        name.className = 'l-name';
        name.textContent = layerDisplayName(el);
        item.appendChild(name);

        // Selection
        item.addEventListener('click', (e) => {
            if (e.shiftKey) {
                if (S.selection.has(el.id)) S.selection.delete(el.id);
                else S.selection.add(el.id);
            } else {
                S.selection.clear();
                S.selection.add(el.id);
            }
            renderSelection();
            renderLayers();
            renderProperties();
        });

        // Rename
        item.addEventListener('dblclick', (e) => {
            if (e.target === eye || eye.contains(e.target)) return;
            e.stopPropagation();
            startLayerRename(item, el);
        });

        // Drag reorder
        item.addEventListener('dragstart', (e) => {
            S.dragSrcLayerId = el.id;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', el.id); } catch (_) {}
        });
        item.addEventListener('dragend', () => {
            item.classList.remove('dragging');
            document.querySelectorAll('.layer-item').forEach(x => x.classList.remove('drag-over'));
        });
        item.addEventListener('dragover', (e) => {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            item.classList.add('drag-over');
        });
        item.addEventListener('dragleave', () => item.classList.remove('drag-over'));
        item.addEventListener('drop', (e) => {
            e.preventDefault();
            item.classList.remove('drag-over');
            const srcId = S.dragSrcLayerId;
            S.dragSrcLayerId = null;
            if (!srcId || srcId === el.id) return;
            reorderLayer(srcId, el.id);
        });

        return item;
    }
    function layerDisplayName(el) {
        if (el.name) return el.name;
        if (el.type === 'text') {
            const tx = (el.text || '').trim().slice(0, 30);
            return tx || t('text');
        }
        return t(el.type);
    }
    function startLayerRename(item, el) {
        const nameEl = item.querySelector('.l-name');
        if (!nameEl) return;
        const input = document.createElement('input');
        input.className = 'l-name-input';
        input.value = el.name || layerDisplayName(el);
        nameEl.replaceWith(input);
        input.focus();
        input.select();
        const commit = () => {
            const v = input.value.trim();
            if (v && v !== el.name) {
                pushHistory();
                el.name = v;
                scheduleSave();
            }
            renderLayers();
        };
        input.addEventListener('blur', commit);
        input.addEventListener('keydown', (e) => {
            e.stopPropagation();
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { input.value = el.name || layerDisplayName(el); input.blur(); }
        });
    }
    function reorderLayer(srcId, targetId) {
        if (!S.board) return;
        const sorted = [...S.board.layers].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0));
        const srcIdx = sorted.findIndex(e => e.id === srcId);
        const tgtIdx = sorted.findIndex(e => e.id === targetId);
        if (srcIdx < 0 || tgtIdx < 0) return;
        pushHistory();
        const [moved] = sorted.splice(srcIdx, 1);
        sorted.splice(tgtIdx, 0, moved);
        sorted.forEach((el, i) => { el.zIndex = i; });
        S.board.layers = sorted;
        renderElements();
        renderLayers();
        renderSelection();
        scheduleSave();
    }

    // ========================================================================
    // PROPERTIES POPUP
    // ========================================================================
    let currentPropEl = null;

    function renderProperties() {
        clearNode(D.props);
        currentPropEl = null;
        const els = getSelected();
        if (els.length !== 1) { D.props.classList.remove('visible'); return; }
        const el = els[0];
        currentPropEl = el;

        const container = D.props;
        const addRow = (children) => {
            const row = document.createElement('div');
            row.className = 'prop-row';
            for (const c of children) row.appendChild(c);
            container.appendChild(row);
        };
        const addLabel = (text) => {
            const l = document.createElement('div');
            l.className = 'prop-label';
            l.textContent = text;
            return l;
        };
        const addDivider = () => {
            const d = document.createElement('div');
            d.className = 'prop-divider';
            container.appendChild(d);
        };

        const snapshotBefore = () => {
            if (!S.propSnapshot) S.propSnapshot = JSON.stringify(S.board.layers);
        };
        const commitAfter = () => {
            if (S.propSnapshot && JSON.stringify(S.board.layers) !== S.propSnapshot) {
                S.history.push(S.propSnapshot);
                if (S.history.length > CONFIG.HISTORY_LIMIT) S.history.shift();
                S.redoStack.length = 0;
                updateUndoRedoUI();
                scheduleSave();
            }
            S.propSnapshot = null;
        };

        if (el.type === 'text') {
            // Font size
            const sizeInput = document.createElement('input');
            sizeInput.type = 'number';
            sizeInput.min = CONFIG.TEXT_MIN_SIZE;
            sizeInput.max = 200;
            sizeInput.value = el.fontSize || 18;
            sizeInput.className = 'prop-input';
            sizeInput.addEventListener('focus', snapshotBefore);
            sizeInput.addEventListener('input', () => {
                el.fontSize = clamp(parseInt(sizeInput.value, 10) || 18, CONFIG.TEXT_MIN_SIZE, 200);
                renderElements();
            });
            sizeInput.addEventListener('change', commitAfter);
            addRow([addLabel(t('size')), sizeInput]);

            // Align
            const alignGroup = document.createElement('div');
            alignGroup.className = 'prop-group';
            ['left', 'center', 'right'].forEach(al => {
                const b = document.createElement('button');
                b.className = 'prop-btn' + (el.textAlign === al ? ' active' : '');
                b.textContent = al === 'left' ? '⯇' : al === 'center' ? '≡' : '⯈';
                b.title = t(al);
                b.addEventListener('click', () => {
                    snapshotBefore();
                    el.textAlign = al;
                    renderElements();
                    renderProperties();
                    commitAfter();
                });
                alignGroup.appendChild(b);
            });
            addRow([addLabel(t('textAlign')), alignGroup]);

            // Bold/Italic
            const styleGroup = document.createElement('div');
            styleGroup.className = 'prop-group';
            const boldBtn = document.createElement('button');
            boldBtn.className = 'prop-btn' + (el.fontWeight === 'bold' ? ' active' : '');
            boldBtn.textContent = 'B';
            boldBtn.style.fontWeight = 'bold';
            boldBtn.title = t('bold');
            boldBtn.addEventListener('click', () => {
                snapshotBefore();
                el.fontWeight = el.fontWeight === 'bold' ? 'normal' : 'bold';
                renderElements();
                renderProperties();
                commitAfter();
            });
            const italicBtn = document.createElement('button');
            italicBtn.className = 'prop-btn' + (el.fontStyle === 'italic' ? ' active' : '');
            italicBtn.textContent = 'I';
            italicBtn.style.fontStyle = 'italic';
            italicBtn.title = t('italic');
            italicBtn.addEventListener('click', () => {
                snapshotBefore();
                el.fontStyle = el.fontStyle === 'italic' ? 'normal' : 'italic';
                renderElements();
                renderProperties();
                commitAfter();
            });
            styleGroup.appendChild(boldBtn);
            styleGroup.appendChild(italicBtn);
            addRow([addLabel(t('size')), styleGroup]);

            // Color
            const colorInput = document.createElement('input');
            colorInput.type = 'color';
            colorInput.value = el.color || '#111111';
            colorInput.className = 'prop-input';
            colorInput.addEventListener('focus', snapshotBefore);
            colorInput.addEventListener('input', () => {
                el.color = colorInput.value;
                renderElements();
            });
            colorInput.addEventListener('change', commitAfter);
            addRow([addLabel(t('color')), colorInput]);

        } else if (el.type === 'rectangle' || el.type === 'circle') {
            // Fill
            const fillRow = document.createElement('div');
            fillRow.className = 'prop-group';
            const fillInput = document.createElement('input');
            fillInput.type = 'color';
            fillInput.value = (el.fill && el.fill !== 'none') ? el.fill : '#ffd9b3';
            fillInput.className = 'prop-input';
            fillInput.addEventListener('focus', snapshotBefore);
            fillInput.addEventListener('input', () => {
                el.fill = fillInput.value;
                renderElements();
            });
            fillInput.addEventListener('change', commitAfter);
            const noFillBtn = document.createElement('button');
            noFillBtn.className = 'prop-btn' + ((!el.fill || el.fill === 'none') ? ' active' : '');
            noFillBtn.textContent = t('noFill');
            noFillBtn.addEventListener('click', () => {
                snapshotBefore();
                el.fill = (el.fill && el.fill !== 'none') ? 'none' : '#ffd9b3';
                renderElements();
                renderProperties();
                commitAfter();
            });
            fillRow.appendChild(fillInput);
            fillRow.appendChild(noFillBtn);
            addRow([addLabel(t('fill')), fillRow]);

            // Border color
            const bColor = document.createElement('input');
            bColor.type = 'color';
            bColor.value = (el.border && el.border.color) || '#000000';
            bColor.className = 'prop-input';
            bColor.addEventListener('focus', snapshotBefore);
            bColor.addEventListener('input', () => {
                el.border = el.border || { color: '#000000', width: 2 };
                el.border.color = bColor.value;
                renderElements();
            });
            bColor.addEventListener('change', commitAfter);
            addRow([addLabel(t('border')), bColor]);

            // Border width
            const bWidth = document.createElement('input');
            bWidth.type = 'number';
            bWidth.min = 0;
            bWidth.max = 40;
            bWidth.value = (el.border && el.border.width) || 2;
            bWidth.className = 'prop-input';
            bWidth.addEventListener('focus', snapshotBefore);
            bWidth.addEventListener('input', () => {
                el.border = el.border || { color: '#000000', width: 2 };
                el.border.width = clamp(parseInt(bWidth.value, 10) || 0, 0, 40);
                renderElements();
            });
            bWidth.addEventListener('change', commitAfter);
            addRow([addLabel(t('width')), bWidth]);

            // Radius (rectangle only)
            if (el.type === 'rectangle') {
                const radius = document.createElement('input');
                radius.type = 'number';
                radius.min = 0;
                radius.max = 200;
                radius.value = (el.border && el.border.radius) || 0;
                radius.className = 'prop-input';
                radius.addEventListener('focus', snapshotBefore);
                radius.addEventListener('input', () => {
                    el.border = el.border || { color: '#000000', width: 2 };
                    el.border.radius = clamp(parseInt(radius.value, 10) || 0, 0, 200);
                    renderElements();
                });
                radius.addEventListener('change', commitAfter);
                addRow([addLabel(t('radius')), radius]);
            }

        } else if (el.type === 'image') {
            const wInput = document.createElement('input');
            wInput.type = 'number';
            wInput.min = 10;
            wInput.value = Math.round(el.w);
            wInput.className = 'prop-input';
            wInput.addEventListener('focus', snapshotBefore);
            wInput.addEventListener('input', () => {
                const aspect = el.h / el.w;
                el.w = clamp(parseInt(wInput.value, 10) || 10, 10, 5000);
                el.h = Math.round(el.w * aspect);
                renderElements();
                renderSelection();
            });
            wInput.addEventListener('change', commitAfter);
            addRow([addLabel(t('width')), wInput]);

            const hInput = document.createElement('input');
            hInput.type = 'number';
            hInput.min = 10;
            hInput.value = Math.round(el.h);
            hInput.className = 'prop-input';
            hInput.addEventListener('focus', snapshotBefore);
            hInput.addEventListener('input', () => {
                const aspect = el.w / el.h;
                el.h = clamp(parseInt(hInput.value, 10) || 10, 10, 5000);
                el.w = Math.round(el.h * aspect);
                renderElements();
                renderSelection();
            });
            hInput.addEventListener('change', commitAfter);
            addRow([addLabel(t('size')), hInput]);

            addDivider();

            const replaceBtn = document.createElement('button');
            replaceBtn.className = 'prop-btn';
            replaceBtn.textContent = t('replace');
            replaceBtn.addEventListener('click', () => pickImageForElement(el.id));
            const delBtn = document.createElement('button');
            delBtn.className = 'prop-btn danger';
            delBtn.textContent = t('delete');
            delBtn.addEventListener('click', () => {
                pushHistory();
                deleteSelected();
            });
            const actions = document.createElement('div');
            actions.className = 'prop-group';
            actions.appendChild(replaceBtn);
            actions.appendChild(delBtn);
            addRow([addLabel(''), actions]);

        } else if (el.type === 'arrow') {
            const cInput = document.createElement('input');
            cInput.type = 'color';
            cInput.value = (el.style && el.style.color) || '#333333';
            cInput.className = 'prop-input';
            cInput.addEventListener('focus', snapshotBefore);
            cInput.addEventListener('input', () => {
                el.style = el.style || { color: '#333', width: 2 };
                el.style.color = cInput.value;
                renderElements();
            });
            cInput.addEventListener('change', commitAfter);
            addRow([addLabel(t('color')), cInput]);

            const wInput = document.createElement('input');
            wInput.type = 'number';
            wInput.min = 1;
            wInput.max = 20;
            wInput.value = (el.style && el.style.width) || 2;
            wInput.className = 'prop-input';
            wInput.addEventListener('focus', snapshotBefore);
            wInput.addEventListener('input', () => {
                el.style = el.style || { color: '#333', width: 2 };
                el.style.width = clamp(parseInt(wInput.value, 10) || 2, 1, 20);
                renderElements();
            });
            wInput.addEventListener('change', commitAfter);
            addRow([addLabel(t('width')), wInput]);
        }

        D.props.classList.add('visible');
        positionProperties();
    }

    function positionProperties() {
        if (!D.props.classList.contains('visible') || !currentPropEl) return;
        const b = elementBounds(currentPropEl);
        if (!b) return;
        const p = worldToCanvas(b.x, b.y);
        const w = b.w * S.zoom;
        const canvasRect = D.canvas.getBoundingClientRect();
        const popupRect = D.props.getBoundingClientRect();

        // Default: centered above the element
        let left = canvasRect.left + p.x + w / 2 - popupRect.width / 2;
        let top = canvasRect.top + p.y - popupRect.height - 10;

        // If not enough space above, place below
        if (top < canvasRect.top + 8) {
            top = canvasRect.top + p.y + b.h * S.zoom + 10;
        }

        // Clamp within viewport
        left = clamp(left, 8, window.innerWidth - popupRect.width - 8);
        top = clamp(top, 8, window.innerHeight - popupRect.height - 8);

        D.props.style.left = left + 'px';
        D.props.style.top = top + 'px';
    }

    // ========================================================================
    // SELECTION
    // ========================================================================
    function selectOnly(id) {
        S.selection.clear();
        if (id) S.selection.add(id);
        renderSelection();
        renderLayers();
        renderProperties();
    }
    function clearSelection() {
        S.selection.clear();
        renderSelection();
        renderLayers();
        renderProperties();
    }
    function selectAll() {
        if (!S.board) return;
        S.selection.clear();
        for (const el of S.board.layers) S.selection.add(el.id);
        renderSelection();
        renderLayers();
        renderProperties();
    }

    // ========================================================================
    // TOOLS
    // ========================================================================
    function setTool(tool) {
        S.tool = tool;
        document.querySelectorAll('.tool-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.tool === tool);
        });
        D.canvas.classList.remove('tool-text', 'tool-rectangle', 'tool-circle', 'tool-arrow');
        if (['text', 'rectangle', 'circle', 'arrow'].includes(tool)) {
            D.canvas.classList.add('tool-' + tool);
        }
        if (tool === 'image') {
            pickImageToInsert();
            setTool('select');
        }
    }

    // ========================================================================
    // ELEMENT CREATION
    // ========================================================================
    function createElement(type, worldX, worldY, extra) {
        const base = {
            id: uid(type[0]),
            type,
            zIndex: S.board.layers.length,
            ...extra,
        };
        S.board.layers.push(base);
        return base;
    }

    function drawNewElementPreview(tool, sx, sy, ex, ey) {
        clearNode(D.previewG);
        const x = Math.min(sx, ex), y = Math.min(sy, ey);
        const w = Math.abs(ex - sx), h = Math.abs(ey - sy);
        const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent-soft').trim() || '#5b6cff';

        if (tool === 'rectangle') {
            D.previewG.appendChild(svgEl('rect', {
                x, y, width: w, height: h,
                fill: 'rgba(91,108,255,0.08)', stroke: accent, 'stroke-width': 1.5 / S.zoom,
                rx: 4, 'vector-effect': 'non-scaling-stroke',
            }));
        } else if (tool === 'circle') {
            D.previewG.appendChild(svgEl('ellipse', {
                cx: x + w / 2, cy: y + h / 2, rx: w / 2, ry: h / 2,
                fill: 'rgba(91,108,255,0.08)', stroke: accent, 'stroke-width': 1.5 / S.zoom,
                'vector-effect': 'non-scaling-stroke',
            }));
        } else if (tool === 'arrow') {
            D.previewG.appendChild(svgEl('line', {
                x1: sx, y1: sy, x2: ex, y2: ey,
                stroke: accent, 'stroke-width': 2 / S.zoom, 'vector-effect': 'non-scaling-stroke',
            }));
        } else if (tool === 'text') {
            D.previewG.appendChild(svgEl('rect', {
                x, y,
                width: Math.max(w, 160), height: Math.max(h, 40),
                fill: 'rgba(91,108,255,0.08)', stroke: accent, 'stroke-width': 1.5 / S.zoom,
                'stroke-dasharray': '4 3', 'vector-effect': 'non-scaling-stroke',
            }));
        }
    }

    function commitDrawnElement(tool, sx, sy, ex, ey, shiftKey) {
        clearNode(D.previewG);
        let x = Math.min(sx, ex), y = Math.min(sy, ey);
        let w = Math.abs(ex - sx), h = Math.abs(ey - sy);

        if (tool === 'arrow') {
            // For very short drags, make a default-size arrow
            if (Math.abs(ex - sx) < 4 && Math.abs(ey - sy) < 4) {
                ex = sx + 100;
                ey = sy + 50;
            }
            pushHistory();
            const el = createElement('arrow', sx, sy, {
                x1: sx, y1: sy, x2: ex, y2: ey,
                style: { color: '#333333', width: 2 },
            });
            normalizeZ();
            selectOnly(el.id);
            scheduleSave();
            return;
        }

        if (w < 4 && h < 4) { w = 120; h = 80; }
        if (shiftKey) {
            const s = Math.max(w, h);
            if (ex < sx) x = sx - s;
            if (ey < sy) y = sy - s;
            w = s; h = s;
        }

        pushHistory();
        if (tool === 'rectangle') {
            const el = createElement('rectangle', x, y, {
                x, y, w, h,
                fill: 'none',
                border: { color: '#000000', width: 2, radius: 8 },
                text: '',
                fontSize: 16,
                textAlign: 'center',
                color: '#111111',
                fontWeight: 'normal',
                fontStyle: 'normal',
            });
            normalizeZ();
            selectOnly(el.id);
        } else if (tool === 'circle') {
            const el = createElement('circle', x, y, {
                x, y, w, h,
                fill: '#ffe4b5',
                border: { color: '#333333', width: 2 },
            });
            normalizeZ();
            selectOnly(el.id);
        }
        scheduleSave();
    }

    // ========================================================================
    // IMAGE INSERTION / UPLOAD
    // ========================================================================
    let imageFileInput = null;
    let pendingImageElId = null;

    function ensureFileInput() {
        if (imageFileInput) return imageFileInput;
        imageFileInput = document.createElement('input');
        imageFileInput.type = 'file';
        imageFileInput.accept = 'image/*';
        imageFileInput.style.display = 'none';
        imageFileInput.addEventListener('change', () => {
            const f = imageFileInput.files && imageFileInput.files[0];
            if (f) handleImageFile(f, pendingImageElId);
            imageFileInput.value = '';
        });
        document.body.appendChild(imageFileInput);
        return imageFileInput;
    }

    function pickImageToInsert() {
        pendingImageElId = null;
        ensureFileInput().click();
    }
    function pickImageForElement(elId) {
        pendingImageElId = elId;
        ensureFileInput().click();
    }

    async function handleImageFile(file, existingElId, atWorld) {
        if (!S.board) { toast(t('boardNotFound'), 'error'); return; }
        let el = existingElId ? getElement(existingElId) : null;
        const isNew = !el;

        // Get image dimensions to size it
        let naturalW = 200, naturalH = 200;
        try {
            const dims = await getImageDimensions(file);
            naturalW = dims.w;
            naturalH = dims.h;
        } catch (_) {}
        const maxDim = 400;
        const scale = Math.min(1, maxDim / Math.max(naturalW, naturalH));
        const w = Math.round(naturalW * scale);
        const h = Math.round(naturalH * scale);

        const cx = atWorld ? atWorld.x : (S.panX ? (-S.panX + window.innerWidth / 2) / S.zoom : 100);
        const cy = atWorld ? atWorld.y : (S.panY ? (-S.panY + window.innerHeight / 2) / S.zoom : 100);

        if (isNew) {
            pushHistory();
            el = createElement('image', 0, 0, {
                x: Math.round(cx - w / 2), y: Math.round(cy - h / 2), w, h,
                src: '', alt: file.name || 'image', _uploading: true,
            });
            normalizeZ();
            selectOnly(el.id);
            renderElements();
        } else {
            pushHistory();
            el._uploading = true;
            renderElements();
        }

        try {
            const url = await API.upload(file);
            el.src = url;
            el.alt = file.name || 'image';
            delete el._uploading;
            renderElements();
            renderSelection();
            scheduleSave();
            if (isNew) toast(t('pasteImage') || 'Inserted', 'success');
        } catch (err) {
            console.error(err);
            toast(t('uploadError') + ': ' + err.message, 'error');
            if (isNew) {
                S.board.layers = S.board.layers.filter(e => e.id !== el.id);
                S.selection.delete(el.id);
                renderAll();
            } else {
                delete el._uploading;
                renderElements();
            }
        }
    }

    function getImageDimensions(fileOrUrl) {
        return new Promise((resolve, reject) => {
            const url = typeof fileOrUrl === 'string' ? fileOrUrl : URL.createObjectURL(fileOrUrl);
            const img = new Image();
            img.onload = () => {
                resolve({ w: img.naturalWidth || 200, h: img.naturalHeight || 200 });
                if (typeof fileOrUrl !== 'string') URL.revokeObjectURL(url);
            };
            img.onerror = () => {
                reject(new Error('image load failed'));
                if (typeof fileOrUrl !== 'string') URL.revokeObjectURL(url);
            };
            img.src = url;
        });
    }

    // ========================================================================
    // DELETE / COPY / PASTE
    // ========================================================================
    function deleteSelected() {
        if (!S.board || S.selection.size === 0) return;
        if (S.selection.size > 0) pushHistory();
        const ids = new Set(S.selection);
        S.board.layers = S.board.layers.filter(e => !ids.has(e.id));
        S.selection.clear();
        normalizeZ();
        renderAll();
        scheduleSave();
    }

    function copySelection() {
        const els = getSelected();
        if (!els.length) return;
        S.clipboard = els.map(e => clone(e));
        toast(els.length + ' element(s) copied');
    }

    function pasteClipboard() {
        if (!S.clipboard || !S.clipboard.length || !S.board) return;
        pushHistory();
        const newIds = [];
        const zBase = S.board.layers.length;
        S.clipboard.forEach((orig, i) => {
            const el = clone(orig);
            el.id = uid(el.type ? el.type[0] : 'e');
            el.zIndex = zBase + i;
            if (el.type === 'arrow') {
                el.x1 += CONFIG.PASTE_OFFSET;
                el.y1 += CONFIG.PASTE_OFFSET;
                el.x2 += CONFIG.PASTE_OFFSET;
                el.y2 += CONFIG.PASTE_OFFSET;
            } else {
                el.x += CONFIG.PASTE_OFFSET;
                el.y += CONFIG.PASTE_OFFSET;
            }
            S.board.layers.push(el);
            newIds.push(el.id);
        });
        S.selection.clear();
        newIds.forEach(id => S.selection.add(id));
        normalizeZ();
        renderAll();
        scheduleSave();
    }

    // ========================================================================
    // TEXT EDIT OVERLAY
    // ========================================================================
    function startTextEdit(elId) {
        if (!S.board) return;
        const el = getElement(elId);
        if (!el) return;
        if (el.type !== 'text' && el.type !== 'rectangle') return;

        // Commit any existing edit
        if (S.editingId) finishTextEdit(true);

        S.editingId = elId;
        const p = worldToCanvas(el.x, el.y);
        const w = el.w * S.zoom;
        const h = el.h * S.zoom;

        const overlay = document.createElement('div');
        overlay.className = 'text-edit-overlay';
        overlay.style.left = (canvasRect().left - D.canvasWrap.getBoundingClientRect().left + p.x) + 'px';
        overlay.style.top = (canvasRect().top - D.canvasWrap.getBoundingClientRect().top + p.y) + 'px';
        overlay.style.width = w + 'px';
        overlay.style.height = h + 'px';
        overlay.style.fontSize = ((el.fontSize || 18) * S.zoom) + 'px';
        overlay.style.textAlign = el.textAlign || (el.type === 'rectangle' ? 'center' : 'left');
        overlay.style.fontWeight = el.fontWeight || 'normal';
        overlay.style.fontStyle = el.fontStyle || 'normal';
        overlay.style.color = el.color || '#111111';
        if (el.type === 'rectangle') {
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
        }
        overlay.contentEditable = 'true';
        overlay.spellcheck = false;
        overlay.textContent = el.text || '';
        D.canvasWrap.appendChild(overlay);
        overlay.dataset.textEditFor = elId;

        // Hide the element while editing
        el._editing = true;
        renderElements();

        // Select all text
        const range = document.createRange();
        range.selectNodeContents(overlay);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        overlay.focus();

        const finish = (commit) => {
            if (S.editingId !== elId) return;
            const text = overlay.textContent || '';
            overlay.remove();
            S.editingId = null;
            delete el._editing;
            if (commit && text !== (el.text || '')) {
                pushHistory();
                el.text = text;
                // Auto-grow height if text wraps
                scheduleSave();
            }
            renderAll();
        };

        overlay.addEventListener('blur', () => {
            // If user clicked on properties popup or a tool, don't commit yet
            // (we use a small timeout to allow checking relatedTarget)
            setTimeout(() => {
                if (S.editingId !== elId) return;
                const active = document.activeElement;
                if (active && D.props.contains(active)) return;
                finish(true);
            }, 0);
        });
        overlay.addEventListener('keydown', (e) => {
            e.stopPropagation();
            if (e.key === 'Escape') { e.preventDefault(); finish(true); }
            else if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); finish(true); }
        });
    }

    function finishTextEdit(commit) {
        if (!S.editingId) return;
        const overlay = D.canvasWrap.querySelector('.text-edit-overlay');
        if (overlay) {
            if (commit) {
                const el = getElement(S.editingId);
                if (el) {
                    const newText = overlay.textContent || '';
                    if (newText !== (el.text || '')) {
                        pushHistory();
                        el.text = newText;
                        scheduleSave();
                    }
                    delete el._editing;
                }
            } else {
                const el = getElement(S.editingId);
                if (el) delete el._editing;
            }
            overlay.remove();
        }
        S.editingId = null;
        renderAll();
    }

    // ========================================================================
    // ZOOM
    // ========================================================================
    function zoomAt(clientX, clientY, newZoom) {
        newZoom = clamp(newZoom, CONFIG.MIN_ZOOM, CONFIG.MAX_ZOOM);
        const r = canvasRect();
        const sx = clientX - r.left;
        const sy = clientY - r.top;
        // world point under cursor before zoom
        const wx = (sx - S.panX) / S.zoom;
        const wy = (sy - S.panY) / S.zoom;
        S.zoom = newZoom;
        // Adjust pan so world point stays under cursor
        S.panX = sx - wx * S.zoom;
        S.panY = sy - wy * S.zoom;
        updateTransform();
        renderSelection();
        positionProperties();
    }
    function setZoom(newZoom) {
        const r = canvasRect();
        zoomAt(r.left + r.width / 2, r.top + r.height / 2, newZoom);
    }

    // ========================================================================
    // INTERACTIONS (POINTER EVENTS)
    // ========================================================================
    function setupCanvasEvents() {
        D.canvas.addEventListener('pointerdown', onCanvasPointerDown);
        D.canvas.addEventListener('pointermove', onCanvasPointerMove);
        D.canvas.addEventListener('pointerup', onCanvasPointerUp);
        D.canvas.addEventListener('pointercancel', onCanvasPointerUp);
        D.canvas.addEventListener('dblclick', onCanvasDoubleClick);
        D.canvas.addEventListener('wheel', onCanvasWheel, { passive: false });
        D.canvas.addEventListener('contextmenu', e => {
            // allow default context menu
        });

        // Drop files onto canvas
        D.canvasWrap.addEventListener('dragover', e => {
            if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
            }
        });
        D.canvasWrap.addEventListener('drop', e => {
            if (!e.dataTransfer) return;
            const files = Array.from(e.dataTransfer.files || []);
            const imgs = files.filter(f => f.type.startsWith('image/'));
            if (imgs.length) {
                e.preventDefault();
                const world = screenToWorld(e.clientX, e.clientY);
                for (const f of imgs) handleImageFile(f, null, world);
            }
        });

        // ResizeObserver to keep pan consistent
        if (window.ResizeObserver) {
            new ResizeObserver(() => {
                renderSelection();
                positionProperties();
            }).observe(D.canvasWrap);
        }
    }

    function getHitElement(target) {
        let n = target;
        while (n && n !== D.canvas) {
            if (n.dataset && n.dataset.id) return n.dataset.id;
            n = n.parentNode;
        }
        return null;
    }
    function getHitHandle(target) {
        let n = target;
        while (n && n !== D.canvas) {
            if (n.dataset && n.dataset.handle) return n.dataset.handle;
            n = n.parentNode;
        }
        return null;
    }

    function onCanvasPointerDown(e) {
        if (S.editingId) finishTextEdit(true);
        if (e.button === 1) {
            // Middle mouse → pan
            e.preventDefault();
            startPan(e);
            return;
        }
        if (e.button !== 0 && e.pointerType !== 'touch') return;

        // Space held → pan
        if (S.spaceDown) {
            startPan(e);
            return;
        }

        const handle = getHitHandle(e.target);
        if (handle) {
            e.preventDefault();
            startHandleDrag(handle, e);
            return;
        }

        const hitId = getHitElement(e.target);

        // Drawing tool
        if (S.tool !== 'select' && S.tool !== 'image') {
            const w = screenToWorld(e.clientX, e.clientY);
            S.drag = {
                type: 'draw',
                tool: S.tool,
                startWorld: w,
                lastWorld: w,
                moved: false,
            };
            attachDragListeners();
            return;
        }

        if (S.tool === 'image') {
            pickImageToInsert();
            return;
        }

        // Select tool
        if (hitId) {
            const el = getElement(hitId);
            if (!el) return;
            if (el.hidden) return;

            if (e.shiftKey) {
                if (S.selection.has(hitId)) S.selection.delete(hitId);
                else S.selection.add(hitId);
            } else if (!S.selection.has(hitId)) {
                S.selection.clear();
                S.selection.add(hitId);
            }
            renderSelection();
            renderLayers();
            renderProperties();

            // Start move drag
            const world = screenToWorld(e.clientX, e.clientY);
            const snaps = [];
            for (const id of S.selection) {
                const el2 = getElement(id);
                if (!el2) continue;
                if (el2.type === 'arrow') {
                    snaps.push({ id, type: 'arrow', x1: el2.x1, y1: el2.y1, x2: el2.x2, y2: el2.y2 });
                } else {
                    snaps.push({ id, type: el2.type, x: el2.x, y: el2.y });
                }
            }
            S.drag = {
                type: 'move',
                startWorld: world,
                lastWorld: world,
                snaps,
                moved: false,
            };
            attachDragListeners();
            document.body.classList.add('no-select');
            document.body.classList.add('dragging');
            D.canvas.style.cursor = 'move';
        } else {
            // Empty click → start marquee
            if (!e.shiftKey) clearSelection();
            const world = screenToWorld(e.clientX, e.clientY);
            S.drag = {
                type: 'marquee',
                startWorld: world,
                lastWorld: world,
                additive: e.shiftKey,
                baseSelection: e.shiftKey ? new Set(S.selection) : new Set(),
            };
            attachDragListeners();
        }
        e.preventDefault();
    }

    function startPan(e) {
        S.drag = {
            type: 'pan',
            startClientX: e.clientX,
            startClientY: e.clientY,
            startPanX: S.panX,
            startPanY: S.panY,
        };
        D.canvas.classList.add('panning');
        attachDragListeners();
        e.preventDefault();
    }

    function startHandleDrag(handle, e) {
        const els = getSelected();
        if (!els.length) return;

        if (handle === 'arrow-start' || handle === 'arrow-end') {
            const el = els[0];
            if (!el || el.type !== 'arrow') return;
            S.drag = {
                type: 'arrow-endpoint',
                which: handle === 'arrow-start' ? 'start' : 'end',
                elId: el.id,
                x1: el.x1, y1: el.y1, x2: el.x2, y2: el.y2,
                moved: false,
            };
            attachDragListeners();
            e.preventDefault();
            return;
        }

        // Resize
        const b = selectionBounds();
        if (!b) return;
        const startWorld = screenToWorld(e.clientX, e.clientY);
        const snaps = [];
        for (const id of S.selection) {
            const el = getElement(id);
            if (!el) continue;
            if (el.type === 'arrow') {
                snaps.push({ id, type: 'arrow', x1: el.x1, y1: el.y1, x2: el.x2, y2: el.y2 });
            } else {
                snaps.push({ id, type: el.type, x: el.x, y: el.y, w: el.w, h: el.h });
            }
        }
        S.drag = {
            type: 'resize',
            handle,
            startWorld,
            lastWorld: startWorld,
            bounds: { ...b },
            snaps,
            moved: false,
        };
        attachDragListeners();
        e.preventDefault();
    }

    function attachDragListeners() {
        // We use pointermove/up on the canvas element itself (already attached).
        // Also attach on window for out-of-canvas moves.
        window.addEventListener('pointermove', onWindowPointerMove);
        window.addEventListener('pointerup', onWindowPointerUp);
    }
    function detachDragListeners() {
        window.removeEventListener('pointermove', onWindowPointerMove);
        window.removeEventListener('pointerup', onWindowPointerUp);
    }

    let dragHistoryPushed = false;

    function onCanvasPointerMove(e) {
        if (!S.drag) return;
        handleDragMove(e);
    }
    function onWindowPointerMove(e) {
        if (!S.drag) return;
        handleDragMove(e);
    }
    function handleDragMove(e) {
        const d = S.drag;
        if (!d) return;

        if (d.type === 'pan') {
            S.panX = d.startPanX + (e.clientX - d.startClientX);
            S.panY = d.startPanY + (e.clientY - d.startClientY);
            updateTransform();
            return;
        }

        if (d.type === 'move') {
            const world = screenToWorld(e.clientX, e.clientY);
            const dx = world.x - d.startWorld.x;
            const dy = world.y - d.startWorld.y;
            if (!d.moved && Math.abs(dx * S.zoom) < CONFIG.DRAG_THRESHOLD && Math.abs(dy * S.zoom) < CONFIG.DRAG_THRESHOLD) {
                return;
            }
            if (!d.moved) {
                pushHistory();
                d.moved = true;
            }
            for (const snap of d.snaps) {
                const el = getElement(snap.id);
                if (!el) continue;
                if (snap.type === 'arrow') {
                    el.x1 = snap.x1 + dx;
                    el.y1 = snap.y1 + dy;
                    el.x2 = snap.x2 + dx;
                    el.y2 = snap.y2 + dy;
                } else {
                    el.x = snap.x + dx;
                    el.y = snap.y + dy;
                }
            }
            scheduleRender(false);
            return;
        }

        if (d.type === 'arrow-endpoint') {
            const world = screenToWorld(e.clientX, e.clientY);
            const el = getElement(d.elId);
            if (!el) return;
            if (!d.moved) {
                pushHistory();
                d.moved = true;
            }
            if (d.which === 'start') {
                el.x1 = world.x;
                el.y1 = world.y;
            } else {
                el.x2 = world.x;
                el.y2 = world.y;
            }
            scheduleRender(false);
            return;
        }

        if (d.type === 'resize') {
            const world = screenToWorld(e.clientX, e.clientY);
            let dx = world.x - d.startWorld.x;
            let dy = world.y - d.startWorld.y;
            if (!d.moved) {
                if (Math.abs(dx * S.zoom) < 2 && Math.abs(dy * S.zoom) < 2) return;
                pushHistory();
                d.moved = true;
            }
            const h = d.handle;
            const b = d.bounds;
            let nx = b.x, ny = b.y, nw = b.w, nh = b.h;

            if (h.includes('w')) { nx = b.x + dx; nw = b.w - dx; }
            if (h.includes('e')) { nw = b.w + dx; }
            if (h.includes('n')) { ny = b.y + dy; nh = b.h - dy; }
            if (h.includes('s')) { nh = b.h + dy; }

            // Shift for aspect ratio (use ORIGINAL aspect)
            if (e.shiftKey && (h.length === 2)) {
                const aspect = b.w / b.h;
                // prefer the dominant axis
                if (Math.abs(nw - b.w) > Math.abs(nh - b.h)) {
                    nh = nw / aspect;
                    if (h.includes('n')) ny = b.y + b.h - nh;
                } else {
                    nw = nh * aspect;
                    if (h.includes('w')) nx = b.x + b.w - nw;
                }
            }

            // Min size
            if (nw < CONFIG.MIN_ELEMENT_SIZE) {
                if (h.includes('w')) nx = b.x + b.w - CONFIG.MIN_ELEMENT_SIZE;
                nw = CONFIG.MIN_ELEMENT_SIZE;
            }
            if (nh < CONFIG.MIN_ELEMENT_SIZE) {
                if (h.includes('n')) ny = b.y + b.h - CONFIG.MIN_ELEMENT_SIZE;
                nh = CONFIG.MIN_ELEMENT_SIZE;
            }

            // Apply to each element via proportional scaling
            const scaleX = b.w === 0 ? 1 : nw / b.w;
            const scaleY = b.h === 0 ? 1 : nh / b.h;

            for (const snap of d.snaps) {
                const el = getElement(snap.id);
                if (!el) continue;
                if (snap.type === 'arrow') {
                    el.x1 = nx + (snap.x1 - b.x) * scaleX;
                    el.y1 = ny + (snap.y1 - b.y) * scaleY;
                    el.x2 = nx + (snap.x2 - b.x) * scaleX;
                    el.y2 = ny + (snap.y2 - b.y) * scaleY;
                } else {
                    el.x = nx + (snap.x - b.x) * scaleX;
                    el.y = ny + (snap.y - b.y) * scaleY;
                    el.w = Math.max(CONFIG.MIN_ELEMENT_SIZE, snap.w * scaleX);
                    el.h = Math.max(CONFIG.MIN_ELEMENT_SIZE, snap.h * scaleY);
                }
            }
            scheduleRender(false);
            return;
        }

        if (d.type === 'draw') {
            const world = screenToWorld(e.clientX, e.clientY);
            d.lastWorld = world;
            d.moved = true;
            drawNewElementPreview(d.tool, d.startWorld.x, d.startWorld.y, world.x, world.y);
            return;
        }

        if (d.type === 'marquee') {
            const world = screenToWorld(e.clientX, e.clientY);
            d.lastWorld = world;
            updateMarqueeSelection(d);
            return;
        }
    }

    function updateMarqueeSelection(d) {
        const x1 = Math.min(d.startWorld.x, d.lastWorld.x);
        const y1 = Math.min(d.startWorld.y, d.lastWorld.y);
        const x2 = Math.max(d.startWorld.x, d.lastWorld.x);
        const y2 = Math.max(d.startWorld.y, d.lastWorld.y);

        // Draw marquee
        clearNode(D.previewG);
        const p = worldToCanvas(x1, y1);
        const w = (x2 - x1) * S.zoom;
        const h = (y2 - y1) * S.zoom;
        const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent-soft').trim() || '#5b6cff';
        D.previewG.appendChild(svgEl('rect', {
            x: p.x, y: p.y, width: w, height: h,
            fill: 'rgba(91,108,255,0.08)', stroke: accent, 'stroke-width': 1, 'stroke-dasharray': '4 3',
        }));

        // Update selection
        const newSel = new Set(d.additive ? d.baseSelection : []);
        if (S.board) {
            for (const el of S.board.layers) {
                if (el.hidden) continue;
                const b = elementBounds(el);
                if (b.x < x2 && b.x + b.w > x1 && b.y < y2 && b.y + b.h > y1) {
                    newSel.add(el.id);
                }
            }
        }
        S.selection = newSel;
        renderSelection();
        renderLayers();
        renderProperties();
    }

    function onCanvasPointerUp(e) { onPointerUpCommon(e); }
    function onWindowPointerUp(e) { onPointerUpCommon(e); }

    function onPointerUpCommon(e) {
        if (!S.drag) return;
        const d = S.drag;

        if (d.type === 'pan') {
            D.canvas.classList.remove('panning');
        } else if (d.type === 'draw') {
            if (d.tool === 'text') {
                const world = screenToWorld(e.clientX, e.clientY);
                let x = Math.min(d.startWorld.x, world.x);
                let y = Math.min(d.startWorld.y, world.y);
                let w = Math.abs(world.x - d.startWorld.x);
                let h = Math.abs(world.y - d.startWorld.y);
                // Simple click → default size
                if (w < 20) { x = d.startWorld.x; w = 180; }
                if (h < 20) { y = d.startWorld.y; h = 40; }

                pushHistory();
                const el = createElement('text', x, y, {
                    x, y, w, h,
                    text: '',
                    fontSize: 18,
                    textAlign: 'left',
                    color: '#111111',
                    fontWeight: 'normal',
                    fontStyle: 'normal',
                });
                normalizeZ();
                selectOnly(el.id);
                scheduleSave();
                clearNode(D.previewG);
                setTool('select');
                startTextEdit(el.id);
            } else if (d.moved) {
                const world = screenToWorld(e.clientX, e.clientY);
                commitDrawnElement(d.tool, d.startWorld.x, d.startWorld.y, world.x, world.y, e.shiftKey);
                setTool('select');
            } else {
                clearNode(D.previewG);
            }
        } else if (d.type === 'marquee') {
            clearNode(D.previewG);
        } else if (d.type === 'move' || d.type === 'resize' || d.type === 'arrow-endpoint') {
            if (d.moved) scheduleSave();
            document.body.classList.remove('no-select');
            document.body.classList.remove('dragging');
            D.canvas.style.cursor = '';
            renderElements();
            renderSelection();
            renderLayers();
            renderProperties();
        }

        S.drag = null;
        detachDragListeners();
    }

    function onCanvasDoubleClick(e) {
        if (S.tool !== 'select') return;
        const hitId = getHitElement(e.target);
        if (!hitId) return;
        const el = getElement(hitId);
        if (!el) return;
        if (el.type === 'text' || el.type === 'rectangle') {
            e.preventDefault();
            selectOnly(el.id);
            startTextEdit(el.id);
        }
    }

    function onCanvasWheel(e) {
        if (e.ctrlKey || e.metaKey) {
            e.preventDefault();
            const factor = e.deltaY < 0 ? CONFIG.ZOOM_STEP : 1 / CONFIG.ZOOM_STEP;
            zoomAt(e.clientX, e.clientY, S.zoom * factor);
        } else {
            // Pan
            e.preventDefault();
            S.panX -= e.deltaX;
            S.panY -= e.deltaY;
            updateTransform();
        }
    }

    // ========================================================================
    // GLOBAL KEYBOARD
    // ========================================================================
    function isTypingContext() {
        const a = document.activeElement;
        if (!a) return false;
        if (a.isContentEditable) return true;
        const tag = a.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
        return false;
    }

    function setupKeyboard() {
        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('keyup', onKeyUp);
    }

    function onKeyDown(e) {
        // Space → pan mode
        if (e.code === 'Space' && !isTypingContext() && !S.spaceDown) {
            S.spaceDown = true;
            D.canvas.classList.add('can-pan');
            if (!S.drag) e.preventDefault();
        }

        // Ignore most shortcuts while typing
        if (isTypingContext() || (S.editingId && e.key !== 'Escape')) {
            return;
        }

        const mod = e.ctrlKey || e.metaKey;

        if (mod && e.key.toLowerCase() === 'z') {
            e.preventDefault();
            if (e.shiftKey) redo();
            else undo();
            return;
        }
        if (mod && e.key.toLowerCase() === 'y') {
            e.preventDefault();
            redo();
            return;
        }
        if (mod && e.key.toLowerCase() === 's') {
            e.preventDefault();
            doSave();
            return;
        }
        if (mod && e.key.toLowerCase() === 'c') {
            e.preventDefault();
            copySelection();
            return;
        }
        if (mod && e.key.toLowerCase() === 'v') {
            e.preventDefault();
            pasteClipboard();
            return;
        }
        if (mod && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            selectAll();
            return;
        }
        if (mod && e.key.toLowerCase() === 'd') {
            e.preventDefault();
            copySelection();
            pasteClipboard();
            return;
        }

        if (e.key === 'Delete' || e.key === 'Backspace') {
            if (S.selection.size > 0) {
                e.preventDefault();
                deleteSelected();
            }
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            if (S.tool !== 'select') { setTool('select'); return; }
            clearSelection();
            return;
        }

        // Nudge
        if (S.selection.size > 0) {
            const step = e.shiftKey ? 10 : 1;
            let dx = 0, dy = 0;
            if (e.key === 'ArrowLeft') dx = -step;
            else if (e.key === 'ArrowRight') dx = step;
            else if (e.key === 'ArrowUp') dy = -step;
            else if (e.key === 'ArrowDown') dy = step;
            if (dx !== 0 || dy !== 0) {
                e.preventDefault();
                pushHistory();
                for (const id of S.selection) {
                    const el = getElement(id);
                    if (!el) continue;
                    if (el.type === 'arrow') {
                        el.x1 += dx; el.y1 += dy; el.x2 += dx; el.y2 += dy;
                    } else {
                        el.x += dx; el.y += dy;
                    }
                }
                renderElements();
                renderSelection();
                renderProperties();
                scheduleSave();
                return;
            }
        }

        // Tool shortcuts
        if (!mod && !e.shiftKey) {
            const k = e.key.toLowerCase();
            if (k === 'v') { setTool('select'); return; }
            if (k === 't') { setTool('text'); return; }
            if (k === 'r') { setTool('rectangle'); return; }
            if (k === 'o') { setTool('circle'); return; }
            if (k === 'a') { setTool('arrow'); return; }
        }

        // Zoom shortcuts
        if (!mod) {
            if (e.key === '=' || e.key === '+') { e.preventDefault(); setZoom(S.zoom * CONFIG.ZOOM_STEP); return; }
            if (e.key === '-' || e.key === '_') { e.preventDefault(); setZoom(S.zoom / CONFIG.ZOOM_STEP); return; }
            if (e.key === '0') { e.preventDefault(); setZoom(1); return; }
        }
    }

    function onKeyUp(e) {
        if (e.code === 'Space') {
            S.spaceDown = false;
            D.canvas.classList.remove('can-pan');
        }
    }

    // ========================================================================
    // PASTE IMAGE FROM CLIPBOARD
    // ========================================================================
    function setupClipboardPaste() {
        window.addEventListener('paste', (e) => {
            if (isTypingContext() || S.editingId) return;
            const items = e.clipboardData && e.clipboardData.items;
            if (!items) return;
            const imageFiles = [];
            for (const item of items) {
                if (item.kind === 'file' && item.type.startsWith('image/')) {
                    const f = item.getAsFile();
                    if (f) imageFiles.push(f);
                }
            }
            if (imageFiles.length) {
                e.preventDefault();
                const r = canvasRect();
                const world = screenToWorld(r.left + r.width / 2, r.top + r.height / 2);
                for (const f of imageFiles) handleImageFile(f, null, world);
            }
        });
    }

    // ========================================================================
    // EXPORT
    // ========================================================================
    let exportMode = 'embed';
    let exportSizes = { embed: null, link: null };

    function openExportDialog() {
        if (!S.board) return;
        D.exportDialog.classList.add('visible');
        document.querySelectorAll('.radio-option').forEach(opt => {
            opt.classList.toggle('selected', opt.dataset.exportMode === 'embed');
        });
        document.querySelector('input[name="export-mode"][value="embed"]').checked = true;
        exportMode = 'embed';
        exportSizes.embed = null;
        exportSizes.link = null;
        D.sizeEmbed.textContent = t('computing');
        D.sizeLink.textContent = t('computing');
        computeExportSizes();
    }

    async function computeExportSizes() {
        if (!S.board) return;
        // Link mode size (fast)
        try {
            const linkBoard = buildExportBoard('link', {});
            const linkSize = new Blob([JSON.stringify(linkBoard, null, 2)]).size;
            exportSizes.link = linkSize;
            D.sizeLink.textContent = fmtSize(linkSize);
        } catch (e) {
            D.sizeLink.textContent = '—';
        }

        // Embed mode size (fetch + compress images)
        try {
            const images = S.board.layers.filter(e => e.type === 'image' && e.src);
            const srcMap = {};
            await Promise.all(images.map(async (img) => {
                try {
                    srcMap[img.src] = await compressImage(img.src, CONFIG.EXPORT_MAX_DIM, CONFIG.EXPORT_QUALITY);
                } catch (_) {
                    srcMap[img.src] = null;
                }
            }));
            const embedBoard = buildExportBoard('embed', srcMap);
            const embedSize = new Blob([JSON.stringify(embedBoard, null, 2)]).size;
            exportSizes.embed = embedSize;
            D.sizeEmbed.textContent = fmtSize(embedSize);
        } catch (e) {
            console.error(e);
            D.sizeEmbed.textContent = '—';
        }
    }

    function buildExportBoard(mode, srcMap) {
        const board = clone(S.board);
        if (mode === 'embed') {
            for (const el of board.layers) {
                if (el.type === 'image' && el.src) {
                    const dataUrl = srcMap[el.src];
                    if (dataUrl) el.src = dataUrl;
                    else el.src = null;
                }
            }
        } else {
            for (const el of board.layers) {
                if (el.type === 'image') el.src = null;
            }
        }
        delete board.id;
        return board;
    }

    function compressImage(url, maxDim, quality) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                try {
                    let { naturalWidth: w, naturalHeight: h } = img;
                    const scale = Math.min(1, maxDim / Math.max(w, h));
                    w = Math.round(w * scale);
                    h = Math.round(h * scale);
                    const c = document.createElement('canvas');
                    c.width = w;
                    c.height = h;
                    const ctx = c.getContext('2d');
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(0, 0, w, h);
                    ctx.drawImage(img, 0, 0, w, h);
                    resolve(c.toDataURL('image/jpeg', quality));
                } catch (e) { reject(e); }
            };
            img.onerror = () => reject(new Error('image load failed: ' + url));
            img.src = url;
        });
    }

    async function doExport() {
        if (!S.board) return;
        D.exportConfirm.disabled = true;
        const origText = D.exportConfirm.textContent;
        D.exportConfirm.textContent = t('computing');
        try {
            const srcMap = {};
            if (exportMode === 'embed') {
                const images = S.board.layers.filter(e => e.type === 'image' && e.src);
                await Promise.all(images.map(async (img) => {
                    if (srcMap[img.src] !== undefined) return;
                    try {
                        srcMap[img.src] = await compressImage(img.src, CONFIG.EXPORT_MAX_DIM, CONFIG.EXPORT_QUALITY);
                    } catch (_) {
                        srcMap[img.src] = null;
                    }
                }));
            }
            const out = buildExportBoard(exportMode, srcMap);
            const slug = slugify(S.board.title || 'board');
            const filename = `board-${slug}-${dateStamp()}.json`;
            const blob = new Blob([JSON.stringify(out, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast(t('exportSuccess') + ': ' + filename, 'success');
            D.exportDialog.classList.remove('visible');
        } catch (e) {
            console.error(e);
            toast(t('exportError') + ': ' + e.message, 'error');
        } finally {
            D.exportConfirm.disabled = false;
            D.exportConfirm.textContent = origText;
        }
    }

    // ========================================================================
    // BOARDS LIST
    // ========================================================================
    async function loadBoardsList() {
        try {
            S.boards = await API.list();
            renderBoardsList();
        } catch (e) {
            console.error('list failed', e);
            toast('Failed to load boards: ' + e.message, 'error');
        }
    }

    function renderBoardsList() {
        clearNode(D.boardList);
        for (const b of S.boards) {
            const item = document.createElement('div');
            item.className = 'board-item' + (S.board && S.board.id === b.id ? ' active' : '');
            item.dataset.id = b.id;

            const title = document.createElement('div');
            title.className = 'b-title';
            title.textContent = b.title || 'Untitled';
            item.appendChild(title);

            const date = document.createElement('div');
            date.className = 'b-date';
            try {
                const d = new Date(b.updatedAt);
                date.textContent = d.toLocaleDateString(S.lang === 'fa' ? 'fa-IR' : 'en-US', { month: 'short', day: 'numeric' });
            } catch (_) {}
            item.appendChild(date);

            const del = document.createElement('button');
            del.className = 'b-del';
            del.title = 'Delete board';
            del.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>';
            del.addEventListener('click', (e) => {
                e.stopPropagation();
                confirmDeleteBoard(b);
            });
            item.appendChild(del);

            item.addEventListener('click', () => openBoard(b.id));
            D.boardList.appendChild(item);
        }
    }

    function confirmDeleteBoard(b) {
        showConfirm(
            t('confirmDelete'),
            t('confirmDeleteMsg').replace('{name}', b.title || 'Untitled'),
            async () => {
                try {
                    await API.remove(b.id);
                    toast(t('boardDeleted'), 'success');
                    if (S.board && S.board.id === b.id) {
                        S.board = null;
                        S.selection.clear();
                        S.history.length = 0;
                        S.redoStack.length = 0;
                        renderAll();
                        updateSaveStatus('');
                        D.boardTitle.textContent = '';
                    }
                    await loadBoardsList();
                } catch (e) {
                    toast('Delete failed: ' + e.message, 'error');
                }
            }
        );
    }

    async function createNewBoard() {
        // Flush any pending save first
        if (S.board && S.dirty) {
            clearTimeout(S.saveTimer);
            try { await doSave(); } catch (_) {}
        }
        try {
            const id = await API.create();
            await loadBoardsList();
            await openBoard(id);
            toast(t('boardCreated'), 'success');
        } catch (e) {
            toast('Create failed: ' + e.message, 'error');
        }
    }

    async function openBoard(id) {
        if (S.board && S.board.id === id) return;
        // Flush pending save
        if (S.board && S.dirty) {
            clearTimeout(S.saveTimer);
            try { await doSave(); } catch (_) {}
        }
        try {
            const board = await API.load(id);
            S.board = board;
            S.savedSnapshot = JSON.stringify(board);
            S.selection.clear();
            S.history.length = 0;
            S.redoStack.length = 0;
            S.zoom = 1;
            S.panX = 0;
            S.panY = 0;
            S.dirty = false;
            renderAll();
            renderBoardsList();
            updateSaveStatus('saved');
            D.boardTitle.textContent = board.title || t('untitled');
            document.title = (board.title || 'Board') + ' — Editor';
        } catch (e) {
            console.error(e);
            toast(t('boardNotFound') + ': ' + e.message, 'error');
        }
    }

    // ========================================================================
    // TOP BAR EVENT WIRING
    // ========================================================================
    function wireTopBar() {
        // Title edit
        D.boardTitle.addEventListener('focus', () => {
            // Select all on focus for easy rename
            const r = document.createRange();
            r.selectNodeContents(D.boardTitle);
            const s = window.getSelection();
            s.removeAllRanges();
            s.addRange(r);
        });
        D.boardTitle.addEventListener('keydown', (e) => {
            e.stopPropagation();
            if (e.key === 'Enter') {
                e.preventDefault();
                D.boardTitle.blur();
            }
            if (e.key === 'Escape') {
                D.boardTitle.textContent = (S.board && S.board.title) || '';
                D.boardTitle.blur();
            }
        });
        D.boardTitle.addEventListener('blur', () => {
            if (!S.board) return;
            const newTitle = D.boardTitle.textContent.trim() || 'Untitled Board';
            if (newTitle !== S.board.title) {
                S.board.title = newTitle;
                D.boardTitle.textContent = newTitle;
                document.title = newTitle + ' — Editor';
                scheduleSave();
                loadBoardsList().then(renderBoardsList);
            }
        });

        D.btnUndo.addEventListener('click', undo);
        D.btnRedo.addEventListener('click', redo);
        D.btnSave.addEventListener('click', () => {
            clearTimeout(S.saveTimer);
            doSave();
        });
        D.btnExport.addEventListener('click', openExportDialog);

        D.btnTheme.addEventListener('click', () => {
            S.theme = S.theme === 'dark' ? 'light' : 'dark';
            applyTheme();
            try { localStorage.setItem('board-editor-theme', S.theme); } catch (_) {}
            // Re-render selection to update CSS vars in outlines
            renderSelection();
        });

        D.btnLang.addEventListener('click', () => {
            S.lang = S.lang === 'fa' ? 'en' : 'fa';
            try { localStorage.setItem('board-editor-lang', S.lang); } catch (_) {}
            applyLang();
        });

        D.toggleAutoSave.addEventListener('click', () => {
            S.autoSave = !S.autoSave;
            D.toggleAutoSave.classList.toggle('on', S.autoSave);
            try { localStorage.setItem('board-editor-autosave', S.autoSave ? '1' : '0'); } catch (_) {}
            if (S.autoSave && S.dirty) scheduleSave();
        });

        D.toggleLeft.addEventListener('click', () => {
            D.leftSidebar.classList.toggle('collapsed');
        });
        D.toggleRight.addEventListener('click', () => {
            D.rightSidebar.classList.toggle('collapsed');
        });

        D.newBoardBtn.addEventListener('click', createNewBoard);
    }

    function wireTools() {
        D.toolPill.addEventListener('click', (e) => {
            const btn = e.target.closest('.tool-btn');
            if (!btn) return;
            setTool(btn.dataset.tool);
        });
    }

    function wireZoom() {
        D.zoomIn.addEventListener('click', () => setZoom(S.zoom * CONFIG.ZOOM_STEP));
        D.zoomOut.addEventListener('click', () => setZoom(S.zoom / CONFIG.ZOOM_STEP));
        D.zoomPercent.addEventListener('click', () => setZoom(1));
    }

    function wireExportDialog() {
        document.querySelectorAll('.radio-option').forEach(opt => {
            opt.addEventListener('click', () => {
                document.querySelectorAll('.radio-option').forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                const radio = opt.querySelector('input[type="radio"]');
                radio.checked = true;
                exportMode = opt.dataset.exportMode;
            });
        });
        D.exportCancel.addEventListener('click', () => D.exportDialog.classList.remove('visible'));
        D.exportConfirm.addEventListener('click', doExport);
        D.exportDialog.addEventListener('click', (e) => {
            if (e.target === D.exportDialog) D.exportDialog.classList.remove('visible');
        });
    }

    function wireWidthWarning() {
        const checkWidth = () => {
            D.widthWarning.style.display = window.innerWidth < 1024 ? 'block' : 'none';
        };
        window.addEventListener('resize', checkWidth);
        checkWidth();
    }

    // ========================================================================
    // INIT
    // ========================================================================
    async function init() {
        cacheDom();

        // Load persisted prefs
        try {
            S.theme = localStorage.getItem('board-editor-theme') || 'light';
            S.lang = localStorage.getItem('board-editor-lang') || 'en';
            const as = localStorage.getItem('board-editor-autosave');
            S.autoSave = as === null ? true : as === '1';
        } catch (_) {}

        applyTheme();
        applyLang();
        D.toggleAutoSave.classList.toggle('on', S.autoSave);

        // Wire up
        wireTopBar();
        wireTools();
        wireZoom();
        wireExportDialog();
        wireWidthWarning();
        setupCanvasEvents();
        setupKeyboard();
        setupClipboardPaste();

        updateTransform();
        updateEmptyHint();

        // Load board list, and open first board (or create one)
        await loadBoardsList();
        if (S.boards.length > 0) {
            await openBoard(S.boards[0].id);
        } else {
            // No boards: create the first one automatically
            try {
                const id = await API.create();
                await loadBoardsList();
                await openBoard(id);
            } catch (e) {
                console.error('init create failed', e);
            }
        }

        // Warn on unload if dirty
        window.addEventListener('beforeunload', (e) => {
            if (S.dirty && S.board) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    // Expose public API
    return {
        init,
        state: S,
        CONFIG,
        // Exposed for debugging / extensions
        _internal: {
            screenToWorld, worldToScreen, getElement, renderAll,
        },
    };
})();

// Boot
document.addEventListener('DOMContentLoaded', () => {
    window.App.init();
});
</script>
</body>
</html>
    <?php
    exit;
}

// JSON API route
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    switch ($action) {
        case 'list':    handle_list(); break;
        case 'create':  handle_create(); break;
        case 'load':    handle_load(); break;
        case 'save':    handle_save(); break;
        case 'delete':  handle_delete(); break;
        case 'upload':  handle_upload(); break;
        default:        json_err('unknown action', 404);
    }
} catch (Throwable $e) {
    json_err('server error: ' . $e->getMessage(), 500);
}