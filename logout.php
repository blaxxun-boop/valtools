<?php

require __DIR__ . "/inc.php";

session_destroy();

header("Location: ./", true, 303);
