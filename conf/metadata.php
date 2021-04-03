<?php

$meta['client-id'] = array('string');
$meta['client-secret'] = array('string');
$meta['endpoint-auth'] = array('string');
$meta['endpoint-token'] = array('string');
$meta['endpoint-check-token'] = array('string');
$meta['endpoint-usermap'] = array('string');

$meta['group-prefix'] = array('string');
$meta['usermap-rw'] = array('array', '_pattern' => '/B-[0-9A-Z-]+/');
$meta['usermap-teacher'] = array('array', '_pattern' => '/B-[0-9A-Z-]+/');
$meta['usermap-student'] = array('array', '_pattern' => '/B-[0-9A-Z-]+/');
