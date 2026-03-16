import express from 'express'
import { healthRouter } from './routes/health.js'
import { countriesRouter } from './routes/countries.js'

export function buildApp() {
  const app = express()

  app.use(express.json())

  app.use('/health', healthRouter)
  app.use('/countries', countriesRouter)

  return app
}
