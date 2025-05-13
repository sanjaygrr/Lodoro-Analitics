    /**
     * Genera etiquetas para órdenes usando las URLs almacenadas en la base de datos
     *
     * @param array $orderIds
     * @param array $orderTables
     * @return Response
     */
    private function generateLabels($orderIds, $orderTables)
    {
        // Importar la clase que necesitamos para fusionar PDF
        require_once __DIR__ . '/../../../../vendor/tecnickcom/tcpdf/tcpdf.php';
        
        // Array para almacenar URLs de etiquetas
        $labelUrls = [];

        // Si orderTables está vacío, asumir una tabla común
        if (empty($orderTables)) {
            $table = $this->params()->fromPost('table', '');
            $orderTables = array_fill(0, count($orderIds), $table);
        }
        
        // Obtener todas las URLs de etiquetas
        for ($i = 0; $i < count($orderIds); $i++) {
            $id = $orderIds[$i];
            $table = $orderTables[$i] ?? '';

            if (!empty($id) && !empty($table)) {
                try {
                    $orderData = $this->databaseService->fetchOne(
                        "SELECT id, suborder_number, label_url FROM `$table` WHERE id = ?",
                        [$id]
                    );

                    if ($orderData && !empty($orderData['label_url'])) {
                        $labelUrls[] = $orderData['label_url'];
                        
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
                                [$table, $id, 'Etiqueta impresa', $username]
                            );
                        } catch (\Exception $e) {
                            // Ignorar errores de historial
                        }
                    }
                } catch (\Exception $e) {
                    // Ignorar órdenes con error
                    continue;
                }
            }
        }
        
        // Si no hay URLs de etiquetas, generar un PDF de error
        if (empty($labelUrls)) {
            $options = new Options();
            $options->set('isHtml5ParserEnabled', true);
            
            $html = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                    .error { color: #d9534f; font-size: 24px; margin-bottom: 20px; }
                </style>
            </head>
            <body>
                <div class="error">No se encontraron etiquetas disponibles</div>
                <p>Las órdenes seleccionadas no tienen URLs de etiquetas asociadas.</p>
            </body>
            </html>';
            
            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            
            $pdfContent = $dompdf->output();
        } else {
            // Usar TCPDF para combinar PDFs
            try {
                $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
                $pdf->SetCreator('Lodoro Analytics');
                $pdf->SetAuthor('Sistema');
                $pdf->SetTitle('Etiquetas combinadas');
                
                // Eliminar cabeceras predeterminadas
                $pdf->setPrintHeader(false);
                $pdf->setPrintFooter(false);
                
                // Para cada URL de etiqueta
                foreach ($labelUrls as $url) {
                    try {
                        // Descargar el PDF desde la URL
                        $remoteContent = file_get_contents($url);
                        if ($remoteContent) {
                            // Guardar temporalmente el PDF
                            $tempFile = tempnam(sys_get_temp_dir(), 'etiqueta_');
                            file_put_contents($tempFile, $remoteContent);
                            
                            // Importar páginas del PDF
                            $pageCount = $pdf->setSourceFile($tempFile);
                            for ($page = 1; $page <= $pageCount; $page++) {
                                $tpl = $pdf->importPage($page);
                                $pdf->AddPage();
                                $pdf->useTemplate($tpl);
                            }
                            
                            // Eliminar archivo temporal
                            unlink($tempFile);
                        }
                    } catch (\Exception $e) {
                        // Ignorar errores de PDF individual y continuar con los demás
                        continue;
                    }
                }
                
                // Obtener el contenido del PDF combinado
                $pdfContent = $pdf->Output('', 'S');
            } catch (\Exception $e) {
                // En caso de error, crear un PDF simple con mensaje de error
                $options = new Options();
                $options->set('isHtml5ParserEnabled', true);
                
                $html = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
                        .error { color: #d9534f; font-size: 24px; margin-bottom: 20px; }
                    </style>
                </head>
                <body>
                    <div class="error">Error al combinar las etiquetas</div>
                    <p>Se ha producido un error al intentar combinar las etiquetas. Detalles: ' . htmlspecialchars($e->getMessage()) . '</p>
                </body>
                </html>';
                
                $dompdf = new Dompdf($options);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                
                $pdfContent = $dompdf->output();
            }
        }
        
        // Crear respuesta HTTP
        $response = new Response();
        $response->setContent($pdfContent);
        
        // Configurar cabeceras
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Content-Type', 'application/pdf');
        $headers->addHeaderLine('Content-Disposition', 'inline; filename="Etiquetas_' . date('Y-m-d_His') . '.pdf"');
        $headers->addHeaderLine('Content-Length', strlen($pdfContent));
        $headers->addHeaderLine('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
        $headers->addHeaderLine('Pragma', 'public');
        $headers->addHeaderLine('Expires', '0');
        
        return $response;
    }