<?php
if(PHP_SAPI!=='cli')exit;
parse_str($argv[2]??'',$_GET);
require ($argv[1]??'/opt/bitnami/apache/htdocs').'/api/playerProfile.php';
