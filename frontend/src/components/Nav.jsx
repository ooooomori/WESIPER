import { useEffect } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Navbar } from "flowbite-react";
import wesiperLogo from '../assets/images/wesiper-primary-logo.png';
import './nav.css';

const navTheme = {
    collapse: {
        base: "w-full min-[800px]:block min-[800px]:w-auto",
        list: "mt-4 mb-0 flex flex-col min-[800px]:mt-0 min-[800px]:flex-row min-[800px]:space-x-8 min-[800px]:text-sm min-[800px]:font-medium",
    },
    toggle: {
        base: "inline-flex items-center rounded-lg p-2 text-sm text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:text-gray-400 dark:hover:bg-gray-700 dark:focus:ring-gray-600 min-[800px]:hidden",
    },
    link: {
        base: "block py-2 pl-3 pr-4 min-[800px]:p-0",
        active: {
            on: "bg-cyan-700 text-white dark:text-white min-[800px]:bg-transparent min-[800px]:text-cyan-700",
            off: "border-b border-gray-100 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-white min-[800px]:border-0 min-[800px]:hover:bg-transparent min-[800px]:hover:text-cyan-700 min-[800px]:dark:hover:bg-transparent min-[800px]:dark:hover:text-white",
        },
    },
};

const Nav = () => {
    const location = useLocation();

    const pages = [
        {id: "kbodle", name: "KBODLE: 크보들", href: "/kbodle"},
        {id: "bingo", name: "KBO BINGO", href: "/bingo"},
        {id: "kbocandle", name: "KBO CANDLE", href: "/kbocandle"},
        {id: "contact", name: "문의하기", href:"mailto:godmascotvic@gmail.com"},
        {id:"donate", name: "후원하기", href:"https://toss.me/tulowitzki/1000"}
    ];

    useEffect(() => {
        document.title = "WESIPER - " + (pages.find(p => p.href === location.pathname )?.name ?? "웨시퍼");
    }, [location]);
    return (
        <>

            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-TWQDS8MH"
            height="0" width="0" style={{display:"none",visibility:"hidden"}} title="GoogleTagManager"></iframe></noscript>

            

            <Navbar fluid theme={navTheme} className='site-navbar font-family-kbo border-b'>
                
                <Link to="/">
                    <Navbar.Brand as="div">
                        <img src={wesiperLogo} className="h-5 sm:h-7 max-w-[calc(100vw-6rem)] object-contain" alt="WESIPER" />
                    </Navbar.Brand>
                </Link>
                <Navbar.Toggle />
                <Navbar.Collapse>
                    {pages.map(x => <Navbar.Link key={x.id} active={x.href === location.pathname} as={Link} to={x.href} className='text-base'>{x.name}</Navbar.Link>)}
                </Navbar.Collapse>
            </Navbar>


        </>
    )
}

export default Nav;
