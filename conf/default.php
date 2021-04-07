<?php

$conf['client-id'] = '';
$conf['client-secret'] = '';
$conf['endpoint-auth'] = 'https://auth.fit.cvut.cz/oauth/authorize';
$conf['endpoint-token'] = 'https://auth.fit.cvut.cz/oauth/token';
$conf['endpoint-check-token'] = 'https://auth.fit.cvut.cz/oauth/check_token';
$conf['endpoint-usermap'] = 'https://kosapi.fit.cvut.cz/usermap/v1';
$conf['endpoint-kos'] = 'https://kosapi.feld.cvut.cz/api/3';

$conf['group-prefix'] = 'usermap';
$conf['usermap-rw'] = array('B-13000-SUMA-OSOBA-CVUT');
$conf['usermap-teacher'] = array('B-00000-SUMA-ZAMESTNANEC-AKADEMICKY', 'B-00000-SUMA-ZAMESTNANEC-NEAKADEMICKY');
$conf['usermap-student'] = array('B-00000-SUMA-STUDENT');
