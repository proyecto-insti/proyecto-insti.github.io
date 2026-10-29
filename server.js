const express = require('express');
const cors = require('cors');
const db = require('./db');
require('dotenv').config();

const app = express();
app.use(cors());
app.use(express.json());

// Ruta de prueba
app.get('/', (req, res) => {
    res.json({ mensaje: "API de Almuerzos Conscientes activa" });
});

// 1. ESCANEAR CÓDIGO QR Y REGISTRAR ASISTENCIA
app.post('/api/asistencia/escaneo', async (req, res) => {
    const { codigo_qr } = req.body;

    if (!codigo_qr) {
        return res.status(400).json({ error: 'Se requiere un código QR.' });
    }

    try {
        const [estudiantes] = await db.query(
            'SELECT * FROM estudiantes WHERE codigo_qr = ?', 
            [codigo_qr]
        );

        if (estudiantes.length === 0) {
            return res.status(404).json({ mensaje: 'Código QR no registrado.' });
        }

        const estudiante = estudiantes[0];
        const fechaHoy = new Date().toISOString().split('T')[0];

        const [registroPrevio] = await db.query(
            'SELECT * FROM asistencias WHERE estudiante_id = ? AND DATE(fecha_hora) = ?',
            [estudiante.id, fechaHoy]
        );

        if (registroPrevio.length > 0) {
            return res.status(400).json({ 
                mensaje: `El estudiante ${estudiante.nombre} ya registró su consumo hoy.` 
            });
        }

        await db.query('INSERT INTO asistencias (estudiante_id) VALUES (?)', [estudiante.id]);

        res.json({
            exito: true,
            mensaje: `Acceso concedido: ${estudiante.nombre} (${estudiante.grado})`
        });

    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

// 2. REGISTRAR PESO DE RESIDUOS DE LA JORNADA[cite: 1]
app.post('/api/residuos', async (req, res) => {
    const { fecha, peso_sobrante_gramos } = req.body;

    if (!fecha || !peso_sobrante_gramos) {
        return res.status(400).json({ error: 'Ingrese la fecha y el peso sobrante en gramos.' });
    }

    try {
        await db.query(
            'INSERT INTO residuos (fecha, peso_sobrante_gramos) VALUES (?, ?)',
            [fecha, peso_sobrante_gramos]
        );
        res.json({ exito: true, mensaje: 'Residuos registrados correctamente.' });
    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

// 3. MOTOR ANALÍTICO Y CÁLCULOS (%D_g e I_g)[cite: 1]
app.get('/api/analitica/grado/:grado', async (req, res) => {
    const { grado } = req.params;
    const { fecha } = req.query;

    try {
        const [asistencias] = await db.query(
            `SELECT COUNT(a.id) as asistencia_total 
             FROM asistencias a 
             JOIN estudiantes e ON a.estudiante_id = e.id 
             WHERE e.grado = ? AND DATE(a.fecha_hora) = ?`,
            [grado, fecha]
        );

        const [menus] = await db.query('SELECT gramaje_oficial FROM menus WHERE fecha = ?', [fecha]);
        const [residuos] = await db.query('SELECT peso_sobrante_gramos FROM residuos WHERE fecha = ?', [fecha]);

        const asistenciaG = asistencias[0].asistencia_total;
        const gramajeOficial = menus[0] ? menus[0].gramaje_oficial : 0;
        const desperdicioG = residuos[0] ? residuos[0].peso_sobrante_gramos : 0;

        if (asistenciaG === 0) {
            return res.json({ mensaje: `Sin registros de asistencia para el grado ${grado} en esa fecha.` });
        }

        const servidoTotalG = asistenciaG * gramajeOficial; // S_g[cite: 1]
        const indiceDesperdicio = desperdicioG / asistenciaG; // I_g = D_g / A_g[cite: 1]
        const porcentajePerdida = (desperdicioG / servidoTotalG) * 100; // %D_g[cite: 1]

        let semaforo = 'VERDE';
        if (porcentajePerdida > 15) {
            semaforo = 'ROJO';
        } else if (porcentajePerdida > 5) {
            semaforo = 'AMARILLO';
        }

        res.json({
            grado,
            fecha,
            asistencia: asistenciaG,
            servidoTotalGramos: servidoTotalG,
            desperdicioTotalGramos: desperdicioG,
            desperdicioPerCapitaGramos: Number(indiceDesperdicio.toFixed(2)),
            porcentajePerdida: Number(porcentajePerdida.toFixed(2)),
            alertaSemaforo: semaforo
        });

    } catch (error) {
        res.status(500).json({ error: error.message });
    }
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Servidor iniciado en http://localhost:${PORT}`));
app.use(express.static('public'));