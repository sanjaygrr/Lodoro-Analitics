<?php
/**
 * Script para redirigir a una orden de Paris especificada para facilitar las pruebas
 */

// Obtener el ID de la URL o usar uno por defecto
$orderId = isset($_GET['id']) ? $_GET['id'] : "3010061470"; // ID por defecto

// Redirigir a la página de orden con el ID proporcionado
header("Location: /paris-order.php?id=$orderId");
exit;