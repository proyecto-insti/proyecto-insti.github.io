## Almuerzos Conscientes: desperdicio PAE y reportes

El QR `/qr-estudiantes` abre `/desperdicio-pae`, un formulario público que solicita únicamente el grado, el gramaje desperdiciado del PAE y una observación opcional. Los grados disponibles son exactamente: **Quinto 1, Quinto 2, Quinto 3; Séptimo 1, Séptimo 2, Séptimo 3; Octavo 1, Octavo 2, Octavo 3; Décimo 1, Décimo 2, Décimo 3; Once 1, Once 2 y Once 3**.

Cada envío se guarda en MySQL en la tabla `residuos` con `tipo_alimentacion='PAE'`, su grado, fecha, gramaje y observación. El panel del profesor consulta `/api/residuos` y sincroniza los registros para mostrarlos en el resumen, la gráfica y la tabla de reportes. Los registros PAE de quinto, décimo y once se identifican como PAE aunque esos grados también existan en el servicio de almuerzo.

El campo duplicado de observaciones del formulario de menú/gramaje fue retirado. Las observaciones existentes de menú se siguen mostrando en resumen y reporte; las observaciones nuevas del desperdicio y de la entrega PAE también aparecen en la columna **Observaciones** de la tabla de reportes y en la tarjeta de observaciones del resumen.

El porcentaje diario se calcula como: `desperdicio total del día ÷ gramaje servido estimado del día × 100`.

## Organización PAE / almuerzo (versión 5)

El panel **Menú y gramaje** se divide en dos bloques:

**ALMUERZO**
- *Información del servicio* y *Desperdicio del almuerzo*.
- *Registro de proteínas y carbohidratos del almuerzo* (antes “Entrega del PAE”): solo almuerzo. Cada fila tiene componente (proteína, carbohidrato, legumbre, ensalada, fruta, bebida —en litros— u otros), **grado** (o “Todos los grados”), alimento y cantidad servida por ración; el entregado se calcula con el porcentaje. Se guarda en `pae_entregas` (columna `componentes`) y se refleja en `menu_componentes`. La tarjeta anterior “Proteínas, carbohidratos y gramaje” se eliminó: esta la reemplaza.

**PAE**
- *Entrega del PAE*: porcentaje entregado, gramaje por grupo (Sexto 1 a Once 3, 18 grupos; el “gramaje total” opcional se divide en partes iguales) y observación. Se guarda en `pae_entrega_dia` y `pae_entrega_grupos` (`GET/POST /api/entrega-pae-grupos`).
- *Desperdicio del PAE* (panel y formulario QR): cada registro entra a `residuos` con `tipo_alimentacion='PAE'` y aparece en **Desperdicio por grado** del resumen (se actualiza solo cada 10 s).

**Resumen y Reportes** muestran la tabla *Almuerzo y PAE* separada en dos secciones, con columnas de proteínas, carbohidratos, legumbres, ensaladas, frutas, bebidas (L) y otros; la tabla *Entrega del PAE por grupo*; y las observaciones de cada servicio. El *Detalle de registros* incluye las mismas columnas. Los gramos se ven siempre en g y kg.

### Formulario QR
- Al guardar muestra “✔ Guardado: X g de desperdicio PAE en <Grado>”. Si no hay conexión reintenta 3 veces y, si sigue sin servidor, guarda el registro en el celular y lo envía solo después (mensaje corto, sin textos técnicos).
- Cada envío lleva un identificador (`uid_envio`): un reintento nunca duplica el pesaje.
- Si `PUBLIC_BASE_URL` no está definido y el QR se genera desde `localhost`, se usa la IP del computador en la red local para que el celular pueda abrirlo (mismo Wi‑Fi, `app.py` en ejecución).

Para bases ya creadas, las tablas/columnas nuevas se crean solas al usar el sistema; también puedes volver a importar `database/almuerzos_conscientes.sql`.
