import '@fontsource/ibm-plex-sans-arabic/400.css'
import '@fontsource/ibm-plex-sans-arabic/500.css'
import '@fontsource/ibm-plex-sans-arabic/600.css'
import '@fontsource/ibm-plex-sans-arabic/700.css'
import './styles/tokens.css'
import './styles/base.css'
import './styles/components.css'

import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './app/App'

const container = document.getElementById('root')
if (!container) {
  throw new Error('Root element #root was not found.')
}

createRoot(container).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
