<?php

/** Legacy URL — node protocol install removed; relay is auto-configured on service create. */
header('Location: ' . usk_admin_url('nodes'));
exit;
