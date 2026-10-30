<?php
require __DIR__ . '/includes/bootstrap.php';
if (is_post()) { csrf_verify(); do_logout(); flash('good', 'You have been signed out.'); }
redirect('login.php');
