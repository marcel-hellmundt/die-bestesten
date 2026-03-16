import { Router } from 'express'
import { getDb } from '../db.js'

export const countriesRouter = Router()

countriesRouter.get('/', async (_req, res) => {
  const [rows] = await getDb().query('SELECT id, name FROM country ORDER BY name')
  res.json(rows)
})

countriesRouter.get('/:id', async (req, res) => {
  const [rows] = await getDb().query(
    'SELECT id, name FROM country WHERE id = ?',
    [req.params.id]
  ) as [any[], any]

  if (rows.length === 0) {
    res.status(404).json({ message: 'Country not found' })
    return
  }

  res.json(rows[0])
})
