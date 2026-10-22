<?php
session_id("sup1"); session_start();
$_SESSION["user_id"] = 1;
$_SESSION["role"] = "super_admin";
$_SESSION["username"] = "admin";
session_write_close();
echo "ok";
