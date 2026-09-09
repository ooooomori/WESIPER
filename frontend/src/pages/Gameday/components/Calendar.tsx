import type { CustomFlowbiteTheme } from "flowbite-react";
import { Flowbite, Datepicker } from "flowbite-react";
import React, { useEffect } from 'react'

const customTheme: CustomFlowbiteTheme = {
  datepicker: {
    "popup": {
      "root": {
        "base": "absolute top-10 z-50 block pt-2",
        "inline": "relative top-0 z-auto",
        "inner": "inline-block rounded-lg bg-white p-4 shadow-lg dark:bg-gray-700"
      },
      "footer": {
        "base": "",
        "button": {
          "base": "hidden"
        }
      }
    },
    "views": {
      "days": {
        "header": {
          "base": "mb-1 grid grid-cols-7",
          "title": "h-6 text-center text-sm font-medium leading-6 text-gray-500 dark:text-gray-400"
        },
        "items": {
          "base": "grid w-64 grid-cols-7",
          "item": {
            "base": "datepicker block flex-1 cursor-pointer rounded-lg border-0 text-center text-sm font-semibold leading-9 text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-600 ",
            "selected": "bg-blue-700 text-white hover:bg-blue-600",
            "disabled": "text-gray-500"
          }
        }
      },
      "months": {
        "items": {
          "base": "grid w-64 grid-cols-4",
          "item": {
            "base": "block flex-1 cursor-pointer rounded-lg border-0 text-center text-sm font-semibold leading-9 text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-600",
            "selected": "bg-blue-700 text-white hover:bg-blue-600",
            "disabled": "text-gray-500"
          }
        }
      },
      "years": {
        "items": {
          "base": "grid w-64 grid-cols-4",
          "item": {
            "base": "block flex-1 cursor-pointer rounded-lg border-0 text-center text-sm font-semibold leading-9 text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-600",
            "selected": "bg-blue-700 text-white hover:bg-blue-600",
            "disabled": "text-gray-500"
          }
        }
      },
      "decades": {
        "items": {
          "base": "grid w-64 grid-cols-4",
          "item": {
            "base": "block flex-1 cursor-pointer rounded-lg border-0 text-center text-sm font-semibold leading-9  text-gray-900 hover:bg-gray-100 dark:text-white dark:hover:bg-gray-600",
            "selected": "bg-blue-700 text-white hover:bg-blue-600",
            "disabled": "text-gray-500"
          }
        }
      }
    }
  }
};

export default function Calendar( {gameDate, setGameDate} ) {
  const handleDateChange = (date: Date) => {
    setGameDate(date);
 };

  return (
    <Flowbite theme={{ theme: customTheme }}>
      <Datepicker 
        className="font-family-NaSqNe w-60 sm:w-72"
        language="ko"
        onSelectedDateChanged={handleDateChange}
      />
    </Flowbite>
  );
}