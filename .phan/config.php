<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// TemplateStylesExtender extends TemplateStyles' sanitizers and hooks, so Phan
// needs that extension on disk to resolve the parent classes and interfaces.
$cfg['directory_list'][] = '../../extensions/TemplateStyles';
$cfg['exclude_analysis_directory_list'][] = '../../extensions/TemplateStyles';

return $cfg;
