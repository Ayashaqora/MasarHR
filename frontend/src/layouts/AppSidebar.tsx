import { NavLink, useLocation } from 'react-router'
import { NAV_ITEMS, isNavItemActive, isNavItemVisible } from '../app/navigation'
import { useAuth } from '../features/auth/context'
import { useI18n } from '../i18n/context'
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
} from '@/components/ui/sidebar'

export function AppSidebar() {
  const { messages } = useI18n()
  const { status, hasPermission } = useAuth()
  const { pathname } = useLocation()
  const items = NAV_ITEMS.filter((item) => isNavItemVisible(item, status, hasPermission))

  return (
    <Sidebar side="left" collapsible="icon" mobileTitle={messages.app.name} mobileDescription={messages.app.mainNavigation}>
      <SidebarHeader className="border-b border-sidebar-border">
        <div className="flex items-center gap-3 px-1 py-1.5">
          <span
            aria-hidden="true"
            className="grid size-9 shrink-0 place-items-center rounded-md bg-sidebar-primary text-lg leading-none font-bold text-sidebar-primary-foreground"
          >
            م
          </span>
          <span className="flex min-w-0 flex-col leading-tight group-data-[collapsible=icon]:hidden">
            <span className="text-base font-bold">{messages.app.name}</span>
            <span className="truncate text-xs text-sidebar-foreground/80">{messages.app.tagline}</span>
          </span>
        </div>
      </SidebarHeader>

      <SidebarContent>
        <nav aria-label={messages.app.mainNavigation}>
          <SidebarGroup>
            <SidebarGroupContent>
              <SidebarMenu>
                {items.map((item) => {
                  const label = messages.nav[item.labelKey]
                  return (
                    <SidebarMenuItem key={item.to}>
                      <SidebarMenuButton asChild isActive={isNavItemActive(item, pathname)} tooltip={label} className="text-sidebar-foreground">
                        <NavLink to={item.to} end={item.end}>
                          <item.icon aria-hidden="true" />
                          <span>{label}</span>
                        </NavLink>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  )
                })}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        </nav>
      </SidebarContent>
      <SidebarRail />
    </Sidebar>
  )
}
