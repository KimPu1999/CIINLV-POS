import axios, { type AxiosRequestConfig } from 'axios'

export interface BackendHealth {
  success: boolean
  status: string
  checks?: Record<string, { status?: string; message?: string }>
}

export interface BusinessMembership {
  id: number
  status: string
  role?: string | null
  joined_at?: string | null
  business: {
    id: string
    name: string
    business_code?: string | null
    status: string
    subscription_status?: string | null
  }
}

export interface User {
  id: number
  name: string
  email: string
  platform_role?: string | null
  status?: string
  memberships?: BusinessMembership[]
  email_verified_at?: string | null
}

export interface ApiResponse<T> {
  success: boolean
  data: T
  message?: string
}

export const api = axios.create({
  baseURL: '/',
  timeout: 10000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

api.interceptors.request.use((config) => {
  const tenantId = window.localStorage.getItem('ciinlv_tenant_id')
  if (tenantId) config.headers.set('X-Tenant-Id', tenantId)
  return config
})

export async function getBackendHealth(): Promise<BackendHealth> {
  return (await api.get<BackendHealth>('/api/v1/health/ready')).data
}

export async function initializeCsrf(): Promise<void> {
  await api.get('/sanctum/csrf-cookie')
}

export async function login(email: string, password: string): Promise<User> {
  await initializeCsrf()
  await api.post<ApiResponse<User>>('/api/v1/auth/login', { email, password, client_type: 'web' })
  return currentUser()
}

export async function currentUser(): Promise<User> {
  return (await api.get<ApiResponse<User>>('/api/v1/auth/me')).data.data
}

export async function logout(): Promise<void> {
  await api.post('/api/v1/auth/logout')
}

export async function request<T>(config: AxiosRequestConfig): Promise<T> {
  try {
    const response = await api.request<ApiResponse<T>>(config)
    return response.data.data
  } catch (error) {
    if (axios.isAxiosError(error) && error.response?.status === 419) {
      await initializeCsrf()
      const response = await api.request<ApiResponse<T>>(config)
      return response.data.data
    }

    throw error
  }
}
