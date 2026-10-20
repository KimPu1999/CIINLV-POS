import { createContext } from 'react'
import type { User } from './api'

export interface AuthContextValue {
  status: 'loading' | 'authenticated' | 'unauthenticated'
  user: User | null
  error: string | null
  login: (email: string, password: string) => Promise<User>
  logout: () => Promise<void>
  retry: () => Promise<void>
  refreshUser: () => Promise<User>
}

export const AuthContext = createContext<AuthContextValue | null>(null)
