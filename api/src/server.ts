import 'dotenv/config'
import { buildApp } from './app.js'

const HOST = process.env.HOST ?? '0.0.0.0'
const PORT = Number(process.env.PORT ?? 3000)

const app = buildApp()

app.listen(PORT, HOST, () => {
  console.log(`Server listening at http://${HOST}:${PORT}`)
})
