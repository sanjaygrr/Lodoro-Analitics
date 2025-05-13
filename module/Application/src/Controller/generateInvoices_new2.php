    /**
     * Genera lista de enlaces a facturas/boletas para órdenes
     *
     * @param array $orderIds
     * @param array $orderTables
     * @return Response
     */
    private function generateInvoices($orderIds, $orderTables)
    {        
        // Array para almacenar URLs de boletas con su ID
        $boletaUrls = [];

        // Si orderTables está vacío, asumir una tabla común
        if (empty($orderTables)) {
            $table = $this->params()->fromPost('table', '');
            $orderTables = array_fill(0, count($orderIds), $table);
        }
        
        // Obtener todas las URLs de boletas
        for ($i = 0; $i < count($orderIds); $i++) {
            $id = $orderIds[$i];
            $table = $orderTables[$i] ?? '';

            if (!empty($id) && !empty($table)) {
                try {
                    // Consulta para buscar diferentes campos de URL de boleta según el marketplace
                    $query = "SELECT id, suborder_number";

                    // Si es PARIS, buscar url_pdf_boleta
                    if (strpos($table, 'PARIS') !== false) {
                        $query .= ", url_pdf_boleta";
                    } else {
                        // Para otros marketplaces, buscar campos alternativos
                        $query .= ", url_pdf_boleta, invoice_url, boleta_url, url_boleta, pdf_url, url_pdf";
                    }

                    $query .= " FROM `$table` WHERE id = ?";

                    $orderData = $this->databaseService->fetchOne($query, [$id]);

                    // Determinar qué campo contiene la URL según disponibilidad
                    $boletaUrl = null;
                    if ($orderData) {
                        // Priorizar campos en este orden
                        $urlFields = [
                            'url_pdf_boleta',
                            'invoice_url',
                            'boleta_url',
                            'url_boleta',
                            'pdf_url',
                            'url_pdf'
                        ];

                        // Buscar el primer campo disponible con URL
                        foreach ($urlFields as $field) {
                            if (isset($orderData[$field]) && !empty($orderData[$field]) && 
                                filter_var($orderData[$field], FILTER_VALIDATE_URL)) {
                                $boletaUrl = $orderData[$field];
                                break;
                            }
                        }

                        // Si se encontró una URL, almacenarla con el ID como clave
                        if ($boletaUrl) {
                            $boletaUrls[$id] = $boletaUrl;
                            
                            // Marcar como impresa
                            $this->databaseService->execute(
                                "UPDATE `$table` SET printed = 1 WHERE id = ?",
                                [$id]
                            );

                            // Registrar en historial
                            try {
                                $username = $this->authService->getIdentity();
                                $this->databaseService->execute(
                                    "INSERT INTO `historial` (tabla, orden_id, accion, usuario, fecha_accion) VALUES (?, ?, ?, ?, NOW())",
                                    [$table, $id, 'Boleta impresa', $username]
                                );
                            } catch (\Exception $e) {
                                // Ignorar errores de historial
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Ignorar órdenes con error
                    continue;
                }
            }
        }
        
        // Si no hay URLs de boleta, mostrar mensaje
        if (empty($boletaUrls)) {
            // Crear una página HTML simple indicando que no hay boletas
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>No hay boletas disponibles</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; text-align: center; }
        .message { color: #d9534f; font-size: 24px; margin: 40px 0; }
    </style>
</head>
<body>
    <div class="message">No se encontraron boletas disponibles</div>
    <p>Las órdenes seleccionadas no tienen URLs de boletas asociadas.</p>
</body>
</html>';
        } else {
            // Crear una página HTML con links directos a las boletas
            $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Boletas</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        h1 { color: #333; }
        .boleta-container { margin-bottom: 20px; }
        .boleta-link { display: block; padding: 10px; background: #f5f5f5; margin: 10px 0; 
                       text-decoration: none; color: #333; border-radius: 4px; }
        .boleta-link:hover { background: #e0e0e0; }
    </style>
</head>
<body>
    <h1>Boletas / Facturas</h1>
    <p>Haga clic en los enlaces a continuación para ver cada documento:</p>';
            
            // Agregar un link para cada URL de boleta
            foreach ($boletaUrls as $orderId => $url) {
                $html .= '
<div class="boleta-container">
    <strong>Orden #' . $orderId . '</strong>
    <a href="' . $url . '" class="boleta-link" target="_blank">Ver Boleta/Factura</a>
</div>';
            }
            
            $html .= '
</body>
</html>';
        }

        // Configurar respuesta HTTP
        $response = new Response();
        $response->setContent($html);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'text/html; charset=UTF-8');
        
        return $response;
    }