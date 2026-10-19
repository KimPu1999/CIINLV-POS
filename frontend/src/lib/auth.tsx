import axios from 'axios'
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { currentUser, login as apiLogin, logout as apiLogout, type User } from './api'
import { AuthContext, type AuthContextValue } from './auth-context'

function isUnauthenticatedError(error: unknown): boolean {
  if (!axios.isAxiosError(error)) return false
  return error.response?.status === 401 || error.response?.status === 419
}

function syncTenantSelection(user: User): void {
  const activeBusinessAdminIds = (user.memberships ?? [])
    .filter((membership) => membership.status === 'active' && membership.role === 'business_admin')
    .map((membership) => membership.business.id)
  const selected = window.localStorage.getItem('ciinlv_tenant_id')

  if (selected && activeBusinessAdminIds.includes(selected)) return
  if (activeBusinessAdminIds.length === 1) {
    window.localStorage.setItem('ciinlv_tenant_id', activeBusinessAdminIds[0])
  } else {
    window.localStorage.removeItem('ciinlv_tenant_id')
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<AuthContextValue['status']>('loading')
  const [user, setUser] = useState<User | null>(null)
  const [error, setError] = useState<string | null>(null)

  const restore = useCallback(async () => {
    setStatus('loading')
    setError(null)

    try {
      const restoredUser = await currentUser()
      syncTenantSelection(restoredUser)
      setUser(restoredUser)
      setStatus('authenticated')
    } catch (requestError) {
      if (isUnauthenticatedError(requestError)) {
        setUser(null)
        setStatus('unauthenticated')
        return
      }

      // Keep the route and existing auth state intact when the API is offline,
      // returns a server error, or cannot be reached during a page refresh.
      setError('Unable to verify the session. Check the Laravel server and retry.')
      setStatus('loading')
    }
  }, [])

  useEffect(() => {
    void restore()
  }, [restore])

  const login = useCallback(async (email: string, password: string) => {
    const authenticatedUser = await apiLogin(email, password)
    syncTenantSelection(authenticatedUser)
    setUser(authenticatedUser)
    setError(null)
    setStatus('authenticated')
    return authenticatedUser
  }, [])

  const logout = useCallback(async () => {
    try {
      await apiLogout()
    } finally {
      setUser(null)
      window.localStorage.removeItem('ciinlv_tenant_id')
      setError(null)
      setStatus('unauthenticated')
    }
  }, [])

  const value = useMemo(() => ({ status, user, error, login, logout, retry: restore }), [status, user, error, login, logout, restore])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
