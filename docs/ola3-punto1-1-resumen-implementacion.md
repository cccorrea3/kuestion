# Resumen de implementación — Ola 3, Punto 1.1: Advertencia de consecuencias al aprobar subconjunto

*Equipo de Kuestion · Septiembre 2026*
*Plan: `docs/ola3-punto1-1-plan-implementacion-kuestion.md` · Cierre técnico: `docs/ola3-punto1-1-cierre-implementacion.md`*

---

## 1. Qué se implementó

El paso de evaluación previo a la promoción definido por la Ola 3 Punto 1.1, sobre la
bandeja de revisión de documentos del Punto 1:

- **Cliente de evaluación** (`QbkContributionService::evaluarSubconjunto()`): consume
  `POST /sesiones-analisis/{id}/evaluar-subconjunto` según el contrato v1.8 §2.6 publicado
  por QuBeKa. Lectura pura, sin efectos; misma lista que irá a `approve`. Errores
  distinguibles: transporte (5xx/504) vs validación (422 con mensaje legible de QuBeKa).
- **Flujo en la bandeja** (`ReviewTray`): "Aprobar seleccionados" ahora evalúa antes de
  promover. Sin consecuencias → promoción directa igual que antes. Con consecuencias →
  panel de advertencia (informativo, no bloquea) con "Confirmar aprobación" / "Volver a la
  selección". Fallo de la evaluación → aviso intermedio con "Aprobar de todas formas" /
  "Reintentar verificación" (P2, confirmada por producto). "Aprobar todo" y el resto de la
  bandeja: sin cambios.
- **UI** (P3, confirmada por producto): panel dentro del panel del documento expandido;
  `descripcion` de QuBeKa mostrada tal cual, nodos afectados citados por texto, encabezado
  legible por tipo (`nodo_huerfano` → "Se promovería como raíz del grafo", `enlace_perdido`
  → "El enlace no se recreará", `sugerencia_no_resuelta` → "Quedarían como nodos separados").
  Cancelar conserva los checkboxes intactos.

## 2. Verificación (resumen)

| Verificación | Resultado |
|---|---|
| Checklist FA contra QuBeKa **real** (sesión 64, 7 nodos) | Los 5 casos: consecuencias reales (3 huérfanos + 3 enlaces perdidos), `total: 0` con selección completa, 422 de ids ajenos con el mismo mensaje que approve, 403/404 legibles |
| E2E real con advertencia (FE-1/2) | Evaluar (total 6) → confirmar → `promocionada` con exactamente +6 nodos; el Q padre rechazado NO entró al grafo |
| E2E real sin advertencia (FE-3, sesión 65 nueva) | Selección completa → `total: 0` → promoción directa → `promocionada` con exactamente +7 nodos |
| E2E fallo de evaluación (FE-4) | QuBeKa inaccesible → aviso P2 visible con aprobar-igual/reintentar; nunca colgado |
| Tests | **579 passed / 1664 assertions / 0 fallos** (24 tests nuevos del punto) |
| Assets | `npm run build` OK; clases nuevas verificadas en el CSS compilado |
| Pint | Limpio |

## 3. Hallazgos principales

1. **QuBeKa ya entregó** (contrato v1.8 + endpoint desplegado): la fase condicionada del plan
   se ejecutó completa contra el servicio real, validando el payload exacto confirmado en la
   ronda §4.3 (NB5: `enlace_perdido` trae ambos extremos — confirmado en la práctica).
2. **Dos defectos encontrados y corregidos durante la Fase C** (capturados por los tests antes
   de cerrar la fase): un fallo del approve posterior caía en el aviso P2 en vez de mostrarse
   como error de aprobación, y los caminos con retorno temprano no liberaban el guard de doble
   envío. Ambos corregidos y con regresión en verde.
3. **`sugerencia_no_resuelta` sin caso real**: la IA no detectó duplicados en las sesiones de
   prueba; el tipo está cubierto por tests con el formato exacto del contrato.
4. **NB6 aplicado al diseño de la prueba**: el contenido de los documentos de prueba colocó
   padre/hijo intra-chunk a propósito (la evaluación solo cubre relaciones intra-chunk).

## 4. Pendiente

- **Verificación visual devtools en `:8001`** (último ítem obligatorio de la sección 3 del
  plan; no hay navegador en este entorno). Checklist ~10 min en el cierre técnico §5. Los
  tests de render ya cubren copy, botones y flujo feliz sin rastro del panel.

## 5. Fuera de alcance (respetado)

Sin lógica de grafo en Kuestion, sin migraciones, sin cambios en `PromocionService`/`approve`,
sin redactar descripciones (llegan listas de QuBeKa), sin recálculo durante la selección.
