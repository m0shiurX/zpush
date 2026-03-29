// Import Wayfinder routes
import { dashboard } from '@/routes';
import { home } from '@/routes';
import { index as settingsIndex } from '@/routes/settings';
import { index as devicesIndex } from '@/routes/devices';
import { index as attendanceIndex } from '@/routes/attendance';
import { index as employeesIndex } from '@/routes/employees';
import { index as cloudServersIndex } from '@/routes/cloud-servers';
import { index as syncIndex } from '@/routes/sync';
import type { MenuConfig } from './types';

/**
 * Application Menu Configuration
 *
 * Navigation Structure:
 * - Main: Dashboard
 * - Devices: Devices, Attendance, Employees
 * - Settings: User Management, Roles, Permissions
 */
export const menuConfig: MenuConfig = {
    /**
     * Main sections displayed in the sidebar icon rail
     */
    mainSections: [
        // Main - Dashboard
        {
            id: 'main',
            titleKey: 'nav.main',
            icon: 'DashboardIcon',
            route: dashboard,
            activePatterns: ['/dashboard','/devices', '/attendance', '/employees','/cloud-servers', '/sync','/settings*', '/account*'],
        },
        // Devices - Device management, attendance, employees
        // {
        //     id: 'devices',
        //     titleKey: 'nav.devices_section',
        //     icon: 'DatabaseIcon',
        //     route: devicesIndex,
        //     activePatterns: ['/devices', '/attendance', '/employees'],
        // },
        // Cloud - Cloud server management, sync monitoring
        // {
        //     id: 'cloud',
        //     titleKey: 'nav.cloud_section',
        //     icon: 'CloudIcon',
        //     route: cloudServersIndex,
        //     activePatterns: ['/cloud-servers', '/sync'],
        // },
    ],

    /**
     * Detail menus shown when a main section is active
     */
    detailMenus: {
        // MAIN - Dashboard
        main: {
            titleKey: 'nav.main',
            groups: [
                {
                    titleKey: 'nav.overview',
                    items: [
                        {
                            titleKey: 'nav.dashboard',
                            route: dashboard,
                            icon: 'DashboardIcon',
                            activePatterns: ['/dashboard'],
                        },
                        {
                            titleKey: 'nav.devices',
                            route: devicesIndex,
                            icon: 'DatabaseIcon',
                            activePatterns: ['/devices'],
                        },
                        {
                            titleKey: 'nav.attendance',
                            route: attendanceIndex,
                            icon: 'ClockIcon',
                            activePatterns: ['/attendance'],
                        },
                        {
                            titleKey: 'nav.employees',
                            route: employeesIndex,
                            icon: 'UsersIcon',
                            activePatterns: ['/employees'],
                        },
                        {
                            titleKey: 'nav.cloud_servers',
                            route: cloudServersIndex,
                            icon: 'CloudIcon',
                            activePatterns: ['/cloud-servers'],
                        },
                        {
                            titleKey: 'nav.sync_monitor',
                            route: syncIndex,
                            icon: 'RefreshIcon',
                            activePatterns: ['/sync'],
                        },
                        // {
                        //     titleKey: 'nav.home',
                        //     route: home,
                        //     icon: 'KanbanIcon',
                        //     activePatterns: ['/'],
                        // },
                    ],
                },
            ],
        },
        // DEVICES - Device management
        // devices: {
        //     titleKey: 'nav.devices_section',
        //     groups: [
        //         {
        //             titleKey: 'nav.device_management',
        //             items: [
        //                 {
        //                     titleKey: 'nav.devices',
        //                     route: devicesIndex,
        //                     icon: 'DatabaseIcon',
        //                     activePatterns: ['/devices*'],
        //                 },
        //                 {
        //                     titleKey: 'nav.attendance',
        //                     route: attendanceIndex,
        //                     icon: 'ClockIcon',
        //                     activePatterns: ['/attendance*'],
        //                 },
        //                 {
        //                     titleKey: 'nav.employees',
        //                     route: employeesIndex,
        //                     icon: 'UsersIcon',
        //                     activePatterns: ['/employees*'],
        //                 },
        //             ],
        //         },
        //     ],
        // },
        // CLOUD - Cloud server and sync management
        cloud: {
            titleKey: 'nav.cloud_section',
            groups: [
                {
                    titleKey: 'nav.cloud_management',
                    items: [
                        {
                            titleKey: 'nav.cloud_servers',
                            route: cloudServersIndex,
                            icon: 'CloudIcon',
                            activePatterns: ['/cloud-servers*'],
                        },
                        {
                            titleKey: 'nav.sync_monitor',
                            route: syncIndex,
                            icon: 'RefreshIcon',
                            activePatterns: ['/sync*'],
                        },
                    ],
                },
            ],
        },
    },

    /**
     * Bottom items in the sidebar (always visible)
     */
    bottomItems: [
        {
            id: 'help',
            titleKey: 'nav.help',
            icon: 'QuestionIcon',
            action: 'modal',
            modalId: 'helpModal',
        },
        {
            id: 'settings',
            titleKey: 'nav.settings',
            icon: 'SettingsIcon',
            route: settingsIndex,
            href: '/settings',
            activePatterns: ['/settings*', '/account*'],
        },
    ],
};

// Named exports for convenience
export const { mainSections, detailMenus, bottomItems } = menuConfig;
