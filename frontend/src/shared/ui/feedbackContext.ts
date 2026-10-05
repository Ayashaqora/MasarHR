import { createContext } from 'react'

/** Lets an operation report its success to the page-level announcement (see OperationFeedback). */
export const FeedbackContext = createContext<(message: string) => void>(() => {})
