import axios from 'axios'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { currentUser, login as apiLogin, logout as apiLogout, type User } from './api'
import { AuthContext, type AuthContextValue } from './auth-context'

function isUnauthenticatedError(error: unknown): boolean {
  if (!axios.isAxiosError(error)) return false
  return error.response?.status === 401 || error.response?.status === 419
}

function syncTenantSelection(user: User): void {
  // Platform Admin tenant context is selected explicitly from Business Management.
  // Do not replace or infer it from tenant memberships.
  if (user.platform_role === 'super_admin') return

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
  const { t } = useTranslation()
  const [status, setStatus] = useState<AuthContextValue['status']>('loading')
  const [user, setUser] = useState<User | null>(null)
  const [error, setError] = useState<string | null>(null)
  const restoreStarted = useRef(false)

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
      setError(t('text.unable_to_verify_the_session_check_the_laravel_server_and_retry'))
      setStatus('loading')
    }
  }, [t])

  useEffect(() => {
    if (restoreStarted.current) return
    restoreStarted.current = true
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

  const refreshUser = useCallback(async () => {
    const refreshedUser = await currentUser()
    syncTenantSelection(refreshedUser)
    setUser(refreshedUser)
    setStatus('authenticated')
    return refreshedUser
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

  const value = useMemo(() => ({ status, user, error, login, logout, retry: restore, refreshUser }), [status, user, error, login, logout, restore, refreshUser])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
