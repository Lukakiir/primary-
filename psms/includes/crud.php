<?php
declare(strict_types=1);

/**
 * Generic list / add / edit / delete engine used by the record-management modules.
 * Each module is described by a config array (see includes/modules.php).
 */

function crud_options(array $f): array
{
    $o = $f['options'] ?? [];
    return is_callable($o) ? $o() : $o;
}

function crud_run(array $c): void
{
    $user     = require_role($c['read'] ?? ['admin', 'teacher']);
    $canWrite = in_array($user['role'], $c['write'] ?? ['admin'], true);

    if (is_post()) {
        csrf_verify();
        if (!$canWrite) forbidden();
        $act = $_POST['a'] ?? '';
        if ($act === 'delete') crud_delete($c);
        if ($act === 'save')   crud_save($c);
        redirect($c['key'] . '.php');
    }

    $a  = $_GET['a'] ?? 'list';
    $id = (int)($_GET['id'] ?? 0);
    if (($a === 'new' || $a === 'edit') && !$canWrite) forbidden();

    if ($a === 'new')  { crud_form($c, null, $c['defaults'] ?? [], []); return; }
    if ($a === 'edit') {
        $r = row('SELECT * FROM ' . $c['table'] . ' WHERE id = ?', [$id]);
        if (!$r) { flash('bad', $c['singular'] . ' not found.'); redirect($c['key'] . '.php'); }
        crud_form($c, $id, $r, []);
        return;
    }
    if ($a === 'view' && isset($c['view'])) { crud_view($c, $id, $canWrite); return; }
    crud_list($c, $canWrite);
}

/* ------------------------------- list ------------------------------- */
function crud_list(array $c, bool $canWrite): void
{
    $where = []; $params = [];
    $search = trim((string)($_GET['q'] ?? ''));
    if ($search !== '' && !empty($c['search'])) {
        $parts = [];
        foreach ($c['search'] as $col) { $parts[] = $col . ' LIKE ?'; $params[] = '%' . $search . '%'; }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    foreach ($c['filters'] ?? [] as $f) {
        $v = (string)($_GET[$f['name']] ?? '');
        if ($v !== '') { $where[] = $f['name'] . ' = ?'; $params[] = $v; }
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $total = (int)val('SELECT COUNT(*) FROM (' . $c['sql'] . ') x' . $w, $params);
    $size  = max(5, (int)cfg('page_size', 20));
    $pages = max(1, (int)ceil($total / $size));
    $page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $data  = rows('SELECT * FROM (' . $c['sql'] . ') x' . $w . ' ORDER BY ' . $c['order'] . ' LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size), $params);

    foreach ($data as &$dr) foreach ($c['computed'] ?? [] as $ck => $cf) $dr[$ck] = $cf($dr);
    unset($dr);

    page_start($c['title'], $c['key']);
    echo '<div class="toolbar"><form class="filters" method="get" role="search">';
    if (!empty($c['search'])) echo '<label class="sr" for="q">Search</label><input type="search" id="q" name="q" value="' . e($search) . '" placeholder="Search ' . e(strtolower($c['title'])) . '…">';
    foreach ($c['filters'] ?? [] as $f) {
        echo '<label class="sr" for="f_' . e($f['name']) . '">' . e($f['label']) . '</label>';
        echo select_html($f['name'], is_callable($f['options']) ? $f['options']() : $f['options'], $_GET[$f['name']] ?? '', 'Any ' . strtolower($f['label']), ['id' => 'f_' . $f['name']]);
    }
    echo '<button class="btn" type="submit">Filter</button>';
    if ($search !== '' || array_filter(array_map(fn($f) => $_GET[$f['name']] ?? '', $c['filters'] ?? []))) echo '<a class="btn ghost" href="' . e(url($c['key'] . '.php')) . '">Clear</a>';
    echo '</form>';
    if ($canWrite) echo '<a class="btn primary" href="' . e(url($c['key'] . '.php?a=new')) . '">+ Add ' . e(strtolower($c['singular'])) . '</a>';
    echo '</div>';

    echo '<div class="card flush"><div class="table-wrap"><table><thead><tr>';
    foreach ($c['columns'] as $label) echo '<th scope="col">' . e($label) . '</th>';
    echo '<th scope="col" class="right">Actions</th></tr></thead><tbody>';
    if (!$data) echo '<tr><td colspan="' . (count($c['columns']) + 1) . '" class="empty">No ' . e(strtolower($c['title'])) . ' found.</td></tr>';
    foreach ($data as $r) {
        echo '<tr>';
        foreach ($c['columns'] as $col => $label) {
            $f = $c['format'][$col] ?? null;
            echo '<td data-label="' . e($label) . '">' . ($f ? $f($r[$col], $r) : e($r[$col])) . '</td>';
        }
        echo '<td class="right actions">';
        if (isset($c['view'])) echo '<a class="link" href="' . e(url($c['key'] . '.php?a=view&id=' . $r['id'])) . '">View</a>';
        if ($canWrite) {
            echo '<a class="link" href="' . e(url($c['key'] . '.php?a=edit&id=' . $r['id'])) . '">Edit</a>';
            echo '<form method="post" class="inline" data-confirm="Delete this ' . e(strtolower($c['singular'])) . '? This cannot be undone.">' . csrf_field() . '<input type="hidden" name="a" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="link danger" type="submit">Delete</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    echo '<div class="pager"><span>' . $total . ' record' . ($total === 1 ? '' : 's') . '</span>';
    if ($pages > 1) {
        $qs = $_GET; echo '<nav aria-label="Pages">';
        for ($p = 1; $p <= $pages; $p++) { $qs['page'] = $p; echo $p === $page ? '<span class="cur" aria-current="page">' . $p . '</span>' : '<a href="?' . e(http_build_query($qs)) . '">' . $p . '</a>'; }
        echo '</nav>';
    }
    echo '</div>';
    page_end();
}

/* ------------------------------- view ------------------------------- */
function crud_view(array $c, int $id, bool $canWrite): void
{
    $r = row('SELECT * FROM ' . $c['table'] . ' WHERE id = ?', [$id]);
    if (!$r) { flash('bad', $c['singular'] . ' not found.'); redirect($c['key'] . '.php'); }
    page_start($c['singular'] . ' profile', $c['key']);
    echo '<p><a class="link" href="' . e(url($c['key'] . '.php')) . '">← Back to ' . e(strtolower($c['title'])) . '</a>';
    if ($canWrite) echo ' · <a class="link" href="' . e(url($c['key'] . '.php?a=edit&id=' . $id)) . '">Edit</a>';
    echo '</p>';
    $c['view']($r);
    page_end();
}

/* ------------------------------- form ------------------------------- */
function crud_form(array $c, ?int $id, array $values, array $errors): void
{
    page_start(($id ? 'Edit ' : 'Add ') . strtolower($c['singular']), $c['key']);
    echo '<div class="card"><form method="post" action="' . e(url($c['key'] . '.php')) . '" novalidate class="form-grid">';
    echo csrf_field() . '<input type="hidden" name="a" value="save"><input type="hidden" name="id" value="' . (int)$id . '">';
    if (!empty($errors['_'])) echo '<div class="alert bad full" role="alert">' . e(implode(' ', (array)$errors['_'])) . '</div>';
    foreach ($c['fields'] as $f) {
        $n = $f['name']; $type = $f['type'] ?? 'text'; $v = $values[$n] ?? '';
        $req = !empty($f['required']) || ($type === 'password' && !$id);
        $err = $errors[$n] ?? null;
        echo '<div class="field' . (($f['wide'] ?? false) ? ' full' : '') . ($err ? ' has-error' : '') . '">';
        echo '<label for="' . e($n) . '">' . e($f['label']) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>';
        $aria = ($err ? ' aria-invalid="true" aria-describedby="' . e($n) . '_err"' : '') . ($req ? ' required' : '');
        if ($type === 'select') {
            $selectAttrs = ['id' => $n];
            if ($req) $selectAttrs['required'] = 'required';
            if ($err) {
                $selectAttrs['aria-invalid'] = 'true';
                $selectAttrs['aria-describedby'] = $n . '_err';
            }
            echo select_html($n, crud_options($f), $v, $req ? 'Select…' : '— none —', $selectAttrs);
        } elseif ($type === 'textarea') {
            echo '<textarea id="' . e($n) . '" name="' . e($n) . '" rows="3"' . $aria . '>' . e($v) . '</textarea>';
        } elseif ($type === 'password') {
            echo '<input type="password" id="' . e($n) . '" name="' . e($n) . '" autocomplete="new-password" minlength="8"' . $aria . '>';
        } else {
            $extra = '';
            if ($type === 'number') $extra = ' step="' . e($f['step'] ?? '1') . '"' . (isset($f['min']) ? ' min="' . e($f['min']) . '"' : '') . (isset($f['max']) ? ' max="' . e($f['max']) . '"' : '');
            echo '<input type="' . e($type) . '" id="' . e($n) . '" name="' . e($n) . '" value="' . e($v) . '" maxlength="' . (int)($f['max_length'] ?? 255) . '"' . $extra . $aria . '>';
        }
        if (!empty($f['hint'])) echo '<small class="hint">' . e($f['hint']) . '</small>';
        if ($err) echo '<small class="err" id="' . e($n) . '_err">' . e($err) . '</small>';
        echo '</div>';
    }
    echo '<div class="form-actions full"><button class="btn primary" type="submit">' . ($id ? 'Save changes' : 'Add ' . e(strtolower($c['singular']))) . '</button><a class="btn ghost" href="' . e(url($c['key'] . '.php')) . '">Cancel</a></div></form></div>';
    page_end();
}

/* ------------------------------- save ------------------------------- */
function crud_save(array $c): void
{
    $id = (int)($_POST['id'] ?? 0) ?: null;
    if ($id && !row('SELECT id FROM ' . $c['table'] . ' WHERE id = ?', [$id])) { flash('bad', 'Record not found.'); redirect($c['key'] . '.php'); }

    $data = []; $errors = []; $raw = [];
    foreach ($c['fields'] as $f) {
        $n = $f['name']; $type = $f['type'] ?? 'text'; $label = $f['label'];
        $v = $_POST[$n] ?? '';
        $v = is_string($v) ? trim($v) : '';
        $raw[$n] = $type === 'password' ? '' : $v;

        if ($type === 'password') {
            if ($v === '') { if (!$id) $errors[$n] = $label . ' is required.'; continue; }
            if (strlen($v) < 8) { $errors[$n] = $label . ' must be at least 8 characters.'; continue; }
            $data[$f['column'] ?? $n] = password_hash($v, PASSWORD_DEFAULT);
            continue;
        }
        if ($v === '') {
            if (!empty($f['required'])) $errors[$n] = $label . ' is required.';
            $data[$n] = null;
            continue;
        }
        $max = (int)($f['max_length'] ?? 255);
        if (in_array($type, ['text', 'tel', 'email', 'textarea'], true) && mb_strlen($v) > $max) { $errors[$n] = $label . ' must be at most ' . $max . ' characters.'; continue; }
        switch ($type) {
            case 'email':  if (!filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[$n] = 'Enter a valid email address.'; break;
            case 'tel':    if (!preg_match('/^[0-9+()\-\s]{6,30}$/', $v)) $errors[$n] = 'Enter a valid phone number.'; break;
            case 'date':   if (!valid_date($v)) $errors[$n] = 'Enter a valid date.'; break;
            case 'number':
                if (!is_numeric($v)) { $errors[$n] = $label . ' must be a number.'; break; }
                if (isset($f['min']) && (float)$v < (float)$f['min']) $errors[$n] = $label . ' must be at least ' . $f['min'] . '.';
                if (isset($f['max']) && (float)$v > (float)$f['max']) $errors[$n] = $label . ' must be at most ' . $f['max'] . '.';
                break;
            case 'select': if (!array_key_exists($v, crud_options($f))) $errors[$n] = 'Choose a valid option for ' . strtolower($label) . '.'; break;
        }
        if (!isset($errors[$n]) && !empty($f['unique']) && row('SELECT id FROM ' . $c['table'] . ' WHERE ' . $n . ' = ? AND id <> ?', [$v, (int)$id])) $errors[$n] = $label . ' "' . $v . '" is already in use.';
        $data[$n] = $v;
    }
    if (!$errors && !empty($c['unique_together'])) {
        $sql = 'SELECT id FROM ' . $c['table'] . ' WHERE id <> ?'; $p = [(int)$id];
        foreach ($c['unique_together'] as $col) { $sql .= ' AND ' . $col . ' = ?'; $p[] = $data[$col]; }
        if (row($sql, $p)) $errors['_'] = ['This combination already exists.'];
    }
    if (!$errors && isset($c['validate'])) $errors = $c['validate']($data, $id) ?: [];
    if ($errors) { http_response_code(422); crud_form($c, $id, $raw, $errors); exit; }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (isset($c['before_save'])) $c['before_save']($data, $id);
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => $k . ' = ?', array_keys($data)));
            q('UPDATE ' . $c['table'] . ' SET ' . $sets . ' WHERE id = ?', array_merge(array_values($data), [$id]));
            $pdo->commit();
            flash('good', $c['singular'] . ' updated.');
        } else {
            q('INSERT INTO ' . $c['table'] . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
            $pdo->commit();
            flash('good', $c['singular'] . ' added.');
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($ex->getCode() === '23000') { http_response_code(422); crud_form($c, $id, $raw, ['_' => ['That change was rejected by the database (duplicate or related record). Please check your entries.']]); exit; }
        throw $ex;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    redirect($c['key'] . '.php');
}

/* ------------------------------ delete ------------------------------ */
function crud_delete(array $c): void
{
    $id = (int)($_POST['id'] ?? 0);
    if (isset($c['before_delete']) && ($msg = $c['before_delete']($id))) { flash('bad', $msg); redirect($c['key'] . '.php'); }
    try {
        $n = q('DELETE FROM ' . $c['table'] . ' WHERE id = ?', [$id])->rowCount();
        flash($n ? 'good' : 'bad', $n ? $c['singular'] . ' deleted.' : 'Record not found.');
    } catch (PDOException $ex) {
        if ($ex->getCode() !== '23000') throw $ex;
        flash('bad', $c['delete_blocked'] ?? 'This record is in use by other records and cannot be deleted.');
    }
    redirect($c['key'] . '.php');
}
