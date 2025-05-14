<?php
// Archivo para manejar acciones sobre órdenes de Paris (marcar como impresa, procesada, etc.)

// Inicializar autoloader y obtener la aplicación
require_once __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../config/container.php';

// Iniciar sesión para poder usar $_SESSION
session_start();

// Aceptar tanto GET como POST para simplificar
// Asegurarse de que el contenido sea JSON y establecer los encabezados adecuados
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff'); // Evitar que los navegadores intenten adivinar el tipo de contenido

// Extraer parámetros (primero de GET, luego de POST, finalmente de datos JSON)
$content = file_get_contents('php://input');
$json = json_decode($content, true);

// Obtener ID y acción de varias fuentes posibles
$orderId = $_GET['id'] ?? $_POST['id'] ?? $json['id'] ?? null;
$action = $_GET['action'] ?? $_POST['action'] ?? $json['action'] ?? null;

// Registrar la solicitud
error_log("paris-order-action.php: recibida solicitud para ID: $orderId, acción: $action");
error_log("Parámetros GET: " . json_encode($_GET));
error_log("Parámetros POST: " . json_encode($_POST));
error_log("Datos JSON: " . json_encode($json));

// Validar que existan los parámetros necesarios
if (empty($orderId) || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos']);
    exit;
}

// Crear conexión a la base de datos
$dbAdapter = $app->get('Laminas\Db\Adapter\AdapterInterface');

try {
    switch ($action) {
        case 'mark-as-printed':
        case 'printed':
            // Mostrar un mensaje de diagnóstico
            error_log("Recibida solicitud para marcar como impresa la orden: $orderId");
            
            // Marcar como impresa
            $stmt = $dbAdapter->query("UPDATE paris_orders SET orden_impresa = 1 WHERE subOrderNumber = ?");
            $result = $stmt->execute([$orderId]);
            
            if ($result->getAffectedRows() > 0) {
                // Registrar en historial (capturando posibles errores)
                try {
                    $username = isset($_SESSION['user']) ? $_SESSION['user'] : 'anonymous';
                    $historyStmt = $dbAdapter->query("INSERT INTO historial (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())");
                    $historyStmt->execute(['Orders_PARIS', $orderId, 'Marcada como impresa', $username]);
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                    error_log("Error al guardar historial: " . $e->getMessage());
                }
                
                echo json_encode(['success' => true, 'message' => 'Orden marcada como impresa correctamente']);
                error_log("Orden $orderId marcada como impresa correctamente");
            } else {
                echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la orden']);
                error_log("No se pudo actualizar la orden $orderId (impresa)");
            }
            break;
            
        case 'mark-as-processed':
        case 'processed':
            // Mostrar un mensaje de diagnóstico
            error_log("Recibida solicitud para marcar como procesada la orden: $orderId");
            
            // Marcar como procesada
            $stmt = $dbAdapter->query("UPDATE paris_orders SET orden_procesada = 1 WHERE subOrderNumber = ?");
            $result = $stmt->execute([$orderId]);
            
            if ($result->getAffectedRows() > 0) {
                // Registrar en historial (capturando posibles errores)
                try {
                    $username = isset($_SESSION['user']) ? $_SESSION['user'] : 'anonymous';
                    $historyStmt = $dbAdapter->query("INSERT INTO historial (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())");
                    $historyStmt->execute(['Orders_PARIS', $orderId, 'Marcada como procesada', $username]);
                } catch (\Exception $e) {
                    // Si hay error al guardar historial, continuar de todos modos
                    error_log("Error al guardar historial: " . $e->getMessage());
                }
                
                echo json_encode(['success' => true, 'message' => 'Orden marcada como procesada correctamente']);
                error_log("Orden $orderId marcada como procesada correctamente");
            } else {
                echo json_encode(['success' => false, 'message' => 'No se pudo actualizar la orden']);
                error_log("No se pudo actualizar la orden $orderId (procesada)");
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
            error_log("Acción no reconocida: $action");
    }
} catch (\Exception $e) {
    error_log("Error al procesar la solicitud: " . $e->getMessage());
    // Asegurarse de que la respuesta sea JSON válido bajo cualquier circunstancia
    try {
        echo json_encode(['success' => false, 'message' => 'Error al procesar la solicitud: ' . $e->getMessage()]);
    } catch (\Exception $jsonError) {
        // Si hay un error al codificar JSON, enviamos una respuesta simple
        header('Content-Type: application/json');
        echo '{"success":false,"message":"Error interno del servidor"}';
    }
}