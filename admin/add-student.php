<?php
session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit;
}
header('Location: manage-students.php?action=new');
exit;
