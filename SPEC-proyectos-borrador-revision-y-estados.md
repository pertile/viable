# Especificación: estado operativo e indicador de artículo pendiente

## Objetivo

Incorporar un indicador editorial para proyectos publicados, validar la relación entre avance y etapa operativa y mejorar la información de fechas en sus visualizaciones.

## Modelo actual relevante

- El tipo de contenido `project` ya usa los estados de publicación de WordPress. El formulario interno guarda los proyectos como publicados.
- El campo ACF `state` contiene `Proyecto`, `En licitación`, `Adjudicado`, `En obras`, `Paralizado`, `Finalizado` o `Cancelado`.
- Ya existen los campos ACF `bid_date`, `award_date`, `start_date` y `end_date`.
- La API del panel lista actualmente solo proyectos publicados. La ficha y los mapas públicos también deben conservar el comportamiento de no exponer contenido no publicado.
- El gráfico temporal calcula “Finalización según pliego” a partir del inicio y el plazo, y hoy lo ubica sobre la barra.

## Requisitos funcionales

### 1. Indicador editorial de artículo pendiente

1. Crear el campo ACF booleano `article_completion_pending`, con etiqueta **Pendiente de completar artículo**.
2. El campo se puede consultar y editar en el formulario interno del proyecto y se muestra como indicador visible en el listado del cliente.
3. Activar el indicador no cambia `post_status`: el proyecto continúa publicado.
4. Exponer el indicador únicamente en el endpoint autenticado del panel interno; no mostrarlo en la ficha pública, mapas ni endpoints públicos.
5. El valor inicial es falso para los proyectos existentes y los nuevos.

### 2. Validación del estado operativo y el avance

La escala numérica asociada a las etiquetas es:

| Estado | Valor |
| --- | ---: |
| Proyecto | 0 |
| En licitación | 10 |
| Adjudicado | 20 |
| En obras | 30 |
| Paralizado | 40 |
| Finalizado | 50 |
| Cancelado | 60 |

1. Mantener las etiquetas actuales en ACF y mapearlas a estos valores para las comparaciones; no hace falta reemplazar el campo textual.
2. Si el avance es mayor que cero, el estado numérico debe ser mayor o igual a 30. Por lo tanto, son válidos únicamente `En obras`, `Paralizado`, `Finalizado` y `Cancelado`.
3. Con avance cero, se permiten las siete etapas.
4. Aplicar la regla en el editor interno, guardado ACF de wp-admin, REST y actualizaciones derivadas de eventos. No depender solo de la validación de interfaz.
5. Ante una combinación inválida, rechazar el guardado manual con un mensaje claro. El recálculo automático debe conservar los valores actuales y no persistir una combinación inválida.

### 3. Fechas bajo las etapas

En el diagrama de estados de la ficha del proyecto, mostrar debajo de cada etapa la fecha que corresponde al campo ya existente, cuando tenga valor:

| Etapa mostrada | Campo fuente |
| --- | --- |
| En licitación | `bid_date` |
| Adjudicación (estado guardado `Adjudicado`) | `award_date` |
| En obras | `start_date` |
| Finalizado | `end_date` |

- Formatear fechas como `d/m/Y`, de manera consistente con las fechas actuales de la ficha.
- No mostrar una fecha vacía ni crear una fecha sustituta.
- La fecha debe acompañar a la etapa tanto en la vista resumida como en la vista expandida del diagrama.
- En el diagrama, mostrar “Adjudicación”; conservar el valor ACF guardado como `Adjudicado` en el selector y en los datos.
- En pantallas angostas, las fechas deben poder ajustarse en varias líneas sin superponerse con etapas, flechas u otras fechas.

### 4. Ubicación de “Finalización según pliego”

- En el gráfico de avance temporal, ubicar la fecha y etiqueta **Finalización según pliego** debajo de la barra.
- Mantener **Última actualización** y **Fin** por encima de la barra.
- Evitar colisiones entre etiquetas inferiores, en particular cuando “Finalización según pliego” y “Finalización prevista” tengan porcentajes próximos o iguales. La solución visual debe conservar legibles ambas fechas y etiquetas en escritorio y móvil.
- No alterar el cálculo del porcentaje, la fecha estimada ni el significado de los otros marcadores.

## Criterios de aceptación

1. Activar y guardar **Pendiente de completar artículo** conserva el valor al volver a abrir el formulario y muestra la etiqueta en el listado interno.
2. Activar el indicador no modifica el estado publicado del proyecto; el indicador no aparece en endpoints ni vistas públicas.
3. Con avance mayor a cero, `Proyecto`, `En licitación` y `Adjudicado` son rechazados tanto por guardados directos como por eventos.
4. Con avance mayor a cero, `En obras`, `Paralizado`, `Finalizado` y `Cancelado` son aceptados; con avance cero se aceptan todos los estados.
5. Los eventos y el recálculo automático no dejan persistida una combinación de estado y avance inválida.
6. Las cuatro fechas se muestran debajo de la etapa correcta cuando están informadas y se omiten cuando no existen.
7. “Finalización según pliego” aparece debajo de la barra, separado de “Finalización prevista”, “Última actualización” y “Fin” en escritorio y móvil.

## Fuera de alcance

- Reemplazar el campo de estado textual por un campo numérico persistido.
- Cambiar cómo se calculan las fechas proyectadas del gráfico.