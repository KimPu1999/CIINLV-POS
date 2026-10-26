import { Activity, AlertTriangle, BarChart3, Boxes, Building2, CircleUserRound, ClipboardList, HelpCircle, History, LayoutDashboard, Package, Receipt, ScanBarcode, Search, Settings, ShieldCheck, ShoppingCart, Store, Truck } from 'lucide-react'
import type { ComponentType } from 'react'

export type NavigationItem = {
  label: string
  path: string
  icon: ComponentType<{ size?: number; className?: string }>
  roles?: ('super_admin' | 'business')[]
  section?: string
  end?: boolean
}

export const platformNavigation: NavigationItem[] = [
  { label: 'Platform overview', path: '/platform/dashboard', icon: LayoutDashboard, roles: ['super_admin'] },
  { label: 'Platform monitoring', path: '/platform/monitoring', icon: Activity, roles: ['super_admin'] },
  { label: 'POS monitoring', path: '/platform/pos-monitoring', icon: Receipt, roles: ['super_admin'] },
  { label: 'Business products', path: '/platform/business-products', icon: Package, roles: ['super_admin'] },
  { label: 'Businesses', path: '/platform/businesses', icon: Building2, roles: ['super_admin'] },
  { label: 'Customers', path: '/platform/customers', icon: CircleUserRound, roles: ['super_admin'] },
  { label: 'Business registration', path: '/platform/businesses/new', icon: ClipboardList, roles: ['super_admin'] },
  { label: 'Audit & security', path: '/platform/audit', icon: Activity, roles: ['super_admin'] },
  { label: 'Marketplace control center', path: '/platform/marketplace', icon: ShoppingCart, roles: ['super_admin'], section: 'Marketplace management', end: true },
  { label: 'Vendor management', path: '/platform/marketplace/vendors', icon: Store, roles: ['super_admin'] },
  { label: 'Product moderation', path: '/platform/marketplace/listings', icon: Package, roles: ['super_admin'] },
  { label: 'Marketplace orders', path: '/platform/marketplace/orders', icon: Receipt, roles: ['super_admin'] },
  { label: 'Payments & refunds', path: '/platform/marketplace/refunds', icon: ShieldCheck, roles: ['super_admin'] },
  { label: 'Commissions', path: '/platform/marketplace/finance', icon: BarChart3, roles: ['super_admin'] },
  { label: 'Vendor payouts', path: '/platform/marketplace/finance', icon: Receipt, roles: ['super_admin'] },
  { label: 'Disputes', path: '/platform/marketplace/disputes', icon: AlertTriangle, roles: ['super_admin'] },
  { label: 'Reports & analytics', path: '/platform/marketplace/reports', icon: BarChart3, roles: ['super_admin'] },
  { label: 'Marketplace audit logs', path: '/platform/marketplace/audit', icon: Activity, roles: ['super_admin'] },
  { label: 'Marketplace settings', path: '/platform/marketplace/settings', icon: Settings, roles: ['super_admin'] },
  { label: 'Help Center', path: '/platform/help', icon: HelpCircle, roles: ['super_admin'], section: 'Help & Policies' },
]

export const businessNavigation: NavigationItem[] = [
  { label: 'Business dashboard', path: '/business/dashboard', icon: LayoutDashboard, roles: ['business'] },
  { label: 'Customers', path: '/business/customers', icon: CircleUserRound, roles: ['business'] },
  { label: 'Branches & dashboards', path: '/business/branches', icon: Store, roles: ['business'] },
  { label: 'Products', path: '/business/products', icon: Package, roles: ['business'] },
  { label: 'Barcode management', path: '/business/barcode-management', icon: ScanBarcode, roles: ['business'] },
  { label: 'Categories', path: '/business/categories', icon: Boxes, roles: ['business'] },
  { label: 'Barcode search', path: '/business/barcode', icon: Search, roles: ['business'] },
  { label: 'Suppliers', path: '/business/suppliers', icon: Truck, roles: ['business'] },
  { label: 'Warehouses', path: '/business/warehouses', icon: Building2, roles: ['business'] },
  { label: 'Inventory', path: '/business/inventory', icon: Boxes, roles: ['business'] },
  { label: 'Purchase orders', path: '/business/purchase-orders', icon: ClipboardList, roles: ['business'] },
  { label: 'Stock transfers', path: '/business/stock-transfers', icon: Truck, roles: ['business'] },
  { label: 'POS checkout', path: '/business/pos', icon: ShoppingCart, roles: ['business'] },
  { label: 'Registers', path: '/business/registers', icon: Receipt, roles: ['business'] },
  { label: 'Staff & salespeople', path: '/business/staff', icon: CircleUserRound, roles: ['business'] },
  { label: 'Sales history', path: '/business/sales', icon: Receipt, roles: ['business'] },
  { label: 'Returns & refunds', path: '/business/returns', icon: CircleUserRound, roles: ['business'] },
  { label: 'Reports & analytics', path: '/business/reports', icon: BarChart3, roles: ['business'] },
  { label: 'Low-stock alerts', path: '/business/alerts', icon: AlertTriangle, roles: ['business'] },
  { label: 'Identity & verification', path: '/business/verification', icon: ShieldCheck, roles: ['business'] },
  { label: 'Settings', path: '/business/settings', icon: Settings, roles: ['business'], section: 'Settings', end: true },
  { label: 'Business Information', path: '/business/settings/business-information', icon: Building2, roles: ['business'], section: 'Settings', end: true },
  { label: 'Marketplace overview', path: '/business/marketplace/overview', icon: Store, roles: ['business'], section: 'Marketplace management' },
  { label: 'Earnings & commissions', path: '/business/marketplace/earnings', icon: BarChart3, roles: ['business'] },
  { label: 'My store', path: '/business/marketplace/store', icon: Building2, roles: ['business'] },
  { label: 'Marketplace products', path: '/business/marketplace/products', icon: Package, roles: ['business'] },
  { label: 'Marketplace orders', path: '/business/marketplace/orders', icon: ShoppingCart, roles: ['business'] },
  { label: 'Shipping & fulfillment', path: '/business/marketplace/shipping', icon: Truck, roles: ['business'] },
  { label: 'Sales history', path: '/business/marketplace/sales-history', icon: History, roles: ['business'] },
  { label: 'Delivery history', path: '/business/marketplace/delivery-history', icon: History, roles: ['business'] },
  { label: 'Customer reviews', path: '/business/marketplace/reviews', icon: CircleUserRound, roles: ['business'] },
  { label: 'Marketplace settings', path: '/business/marketplace/settings', icon: Settings, roles: ['business'] },
  { label: 'Help Center', path: '/business/help', icon: HelpCircle, roles: ['business'], section: 'Help & Policies' },
]

export const platformBusinessNavigation = businessNavigation.filter(({ path }) => [
  '/business/dashboard',
  '/business/customers',
  '/business/branches',
  '/business/products',
  '/business/barcode-management',
  '/business/categories',
  '/business/inventory',
  '/business/sales',
  '/business/reports',
  '/business/help',
].includes(path))
