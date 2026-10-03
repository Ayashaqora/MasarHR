import { Languages, LogOut, UserRound } from 'lucide-react'
import { Fragment } from 'react'
import { Link, useLocation } from 'react-router'
import { NAV_ITEMS } from '../app/navigation'
import { useAuth } from '../features/auth/context'
import { useI18n } from '../i18n/context'
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Separator } from '@/components/ui/separator'
import { SidebarTrigger } from '@/components/ui/sidebar'
import { Ltr } from '../shared/ui/Ltr'
import { isLocale } from '../i18n/locales'

interface Crumb {
  label: string
  to?: string
}

function useBreadcrumbs(): Crumb[] {
  const { messages } = useI18n()
  const { pathname } = useLocation()

  if (pathname === '/') {
    return [{ label: messages.nav.home }]
  }

  const section = NAV_ITEMS.find((item) => !item.end && (pathname === item.to || pathname.startsWith(`${item.to}/`)))
  const crumbs: Crumb[] = [{ label: messages.nav.home, to: '/' }]

  if (section) {
    const isRoot = pathname === section.to
    crumbs.push({ label: messages.nav[section.labelKey], to: isRoot ? undefined : section.to })
    // The only nested, non-section screen: Employee 360 (/employees/:personId/relationships/:relationshipId).
    if (section.to === '/employees' && /^\/employees\/[^/]+\/relationships\/[^/]+$/.test(pathname)) {
      crumbs.push({ label: messages.employee360.title })
    }
    return crumbs
  }

  if (pathname === '/login') {
    crumbs.push({ label: messages.auth.pageTitle })
    return crumbs
  }

  return [{ label: messages.nav.home, to: '/' }, { label: messages.errors.notFoundTitle }]
}

export function AppHeader() {
  const { messages, locale, setLocale } = useI18n()
  const { status, principal, roles, logout } = useAuth()
  const crumbs = useBreadcrumbs()
  const initial = principal?.display_name.trim().charAt(0) ?? ''

  return (
    <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b bg-background/95 px-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 md:px-4">
      <SidebarTrigger label={messages.app.toggleSidebar} />
      <Separator orientation="vertical" className="me-1 data-[orientation=vertical]:h-5" />

      <Breadcrumb aria-label={messages.app.breadcrumb} className="min-w-0 flex-1">
        <BreadcrumbList className="flex-nowrap">
          {crumbs.map((crumb, index) => {
            const last = index === crumbs.length - 1
            return (
              <Fragment key={`${crumb.label}-${index}`}>
                {index > 0 ? <BreadcrumbSeparator className="rtl:rotate-180 [&>svg]:rtl:rotate-180" /> : null}
                <BreadcrumbItem className={last ? 'min-w-0' : 'hidden sm:inline-flex'}>
                  {last || !crumb.to ? (
                    <BreadcrumbPage className="truncate">{crumb.label}</BreadcrumbPage>
                  ) : (
                    <BreadcrumbLink asChild>
                      <Link to={crumb.to}>{crumb.label}</Link>
                    </BreadcrumbLink>
                  )}
                </BreadcrumbItem>
              </Fragment>
            )
          })}
        </BreadcrumbList>
      </Breadcrumb>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="sm" aria-label={messages.app.language}>
            <Languages aria-hidden="true" />
            <span className="hidden sm:inline">{locale === 'ar' ? messages.app.languageArabic : messages.app.languageEnglish}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuLabel>{messages.app.language}</DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuRadioGroup
            value={locale}
            onValueChange={(value) => {
              if (isLocale(value)) {
                setLocale(value)
              }
            }}
          >
            <DropdownMenuRadioItem value="ar">{messages.app.languageArabic}</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="en">{messages.app.languageEnglish}</DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>

      {status === 'authenticated' && principal ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" aria-label={messages.app.userMenu} className="gap-2">
              <span aria-hidden="true" className="grid size-7 place-items-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
                {initial}
              </span>
              <span className="hidden max-w-40 truncate text-sm font-medium sm:inline">{principal.display_name}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="min-w-56">
            <DropdownMenuLabel className="space-y-0.5">
              <span className="block text-xs font-normal text-muted-foreground">{messages.app.signedInAs}</span>
              <span className="block text-sm font-semibold">{principal.display_name}</span>
              <span className="block text-xs font-normal text-muted-foreground">
                <Ltr>{principal.username}</Ltr>
                {' · '}
                {roles[0] ? (locale === 'ar' ? roles[0].name_ar : roles[0].name_en) : messages.app.noRole}
              </span>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              onSelect={() => {
                void logout()
              }}
            >
              <LogOut aria-hidden="true" className="rtl:-scale-x-100" />
              {messages.auth.signOut}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ) : status === 'unauthenticated' ? (
        <Button asChild size="sm">
          <Link to="/login">
            <UserRound aria-hidden="true" />
            {messages.auth.signIn}
          </Link>
        </Button>
      ) : null}
    </header>
  )
}
