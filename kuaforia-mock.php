<?php
// ponytail: mock mínimo para desarrollo
$input = json_decode(file_get_contents('php://input'), true);
$question = $input['question'] ?? '';

$answers = [
    'capital de francia' => 'París es la capital de Francia, conocida como la Ciudad de la Luz.',
    'presidente de españa' => 'El presidente del Gobierno de España es Pedro Sánchez.',
    'que es laravel' => 'Laravel es un framework PHP para desarrollo web con arquitectura MVC.',
];

$answer = 'Lo siento, no tengo información sobre esa pregunta.';
foreach ($answers as $key => $val) {
    if (str_contains(mb_strtolower($question), $key)) {
        $answer = $val;
        break;
    }
}

echo json_encode([
    'answer' => $answer,
    'confidence' => 85.0,
    'sources' => [['title' => 'Fuente de prueba', 'url' => 'https://ejemplo.cl']],
]);
