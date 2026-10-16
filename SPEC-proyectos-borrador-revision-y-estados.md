# Especificación: estado operativo e indicador de artículo pendiente

## Objetivo

Permitir gestionar desde el cliente interno si un proyecto está publicado o en borrador, mantener un indicador editorial independiente, validar la relación entre avance y etapa operativa y mejorar la información de fechas en las visualizaciones.

## Modelo actual relevante

- El tipo de contenido `project` usa los estados de publicación de WordPress. El panel interno muestra y permite cambiar proyectos entre publicados y borradores.
- El campo ACF `state` contiene `Proyecto`, `En licitación`, `Adjudicado`, `En obras`, `Paralizado`, `Finalizado` o `Cancelado`.
- Ya existen los campos ACF `bid_date`, `award_date`, `start_date` y `end_date`.
- La API autenticada del panel lista proyectos publicados y en borrador. La ficha y los mapas públicos deben seguir excluyendo el contenido no publicado.
- El gráfico temporal calcula “Finalización según pliego” a partir del inicio y el plazo, y hoy lo ubica sobre la barra.

## Requisitos funcionales

### 1. Estado de publicación en el cliente interno

1. Incluir **Publicado** y **Borrador** como opciones de publicación en el formulario interno de proyectos.
2. El listado autenticado incluye ambos estados y los identifica claramente.
3. Guardar el formulario conserva la opción elegida en `post_status`; un proyecto nuevo se crea publicado por defecto.
4. El título de un proyecto publicado puede abrir su URL pública. El título de un borrador debe abrir la edición autenticada de WordPress, nunca una URL pública.
5. El endpoint interno solo devuelve proyectos que el usuario actual tenga permiso para editar.
6. Las fichas, mapas, listados y endpoints públicos siguen excluyendo los borradores.

### 2. Indicador editorial de artículo pendiente

1. Crear el campo ACF booleano `article_completion_pending`, con etiqueta **Pendiente de completar artículo**, independiente de si el proyecto está publicado o en borrador.
2. El campo se puede consultar y editar en el formulario interno del proyecto y se muestra como indicador visible en el listado del cliente.
3. Activar el indicador no cambia `post_status`: se conserva el estado de publicación elegido.
4. Exponer el indicador únicamente en el endpoint autenticado del panel interno; no mostrarlo en la ficha pública, mapas ni endpoints públicos.
5. El valor inicial es falso para los proyectos existentes y los nuevos.

### 3. Validación del estado operativo y el avance

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

### 4. Fechas bajo las etapas

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

### 5. Ubicación de “Finalización según pliego”

- En el gráfico de avance temporal, ubicar la fecha y etiqueta **Finalización según pliego** debajo de la barra.
- Mantener **Última actualización** y **Fin** por encima de la barra.
- Evitar colisiones entre etiquetas inferiores, en particular cuando “Finalización según pliego” y “Finalización prevista” tengan porcentajes próximos o iguales. La solución visual debe conservar legibles ambas fechas y etiquetas en escritorio y móvil.
- No alterar el cálculo del porcentaje, la fecha estimada ni el significado de los otros marcadores.

## Criterios de aceptación

1. El cliente lista proyectos publicados y en borrador e indica visualmente el estado de publicación.
2. Cambiar un proyecto a borrador o publicarlo desde el formulario persiste el estado seleccionado; los proyectos nuevos se crean publicados.
3. Los borradores no se filtran a páginas, mapas ni endpoints públicos, y el acceso interno respeta permisos de edición por proyecto.
4. Activar y guardar **Pendiente de completar artículo** conserva el valor al volver a abrir el formulario y muestra la etiqueta en el listado interno, sin cambiar `post_status`.
5. Con avance mayor a cero, `Proyecto`, `En licitación` y `Adjudicado` son rechazados tanto por guardados directos como por eventos.
6. Con avance mayor a cero, `En obras`, `Paralizado`, `Finalizado` y `Cancelado` son aceptados; con avance cero se aceptan todos los estados.
7. Los eventos y el recálculo automático no dejan persistida una combinación de estado y avance inválida.
8. Las cuatro fechas se muestran debajo de la etapa correcta cuando están informadas y se omiten cuando no existen.
9. “Finalización según pliego” aparece debajo de la barra, separado de “Finalización prevista”, “Última actualización” y “Fin” en escritorio y móvil.

## Fuera de alcance

- Reemplazar el campo de estado textual por un campo numérico persistido.
- Cambiar cómo se calculan las fechas proyectadas del gráfico.