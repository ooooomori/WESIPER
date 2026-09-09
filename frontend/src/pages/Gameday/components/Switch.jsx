import React, { useState } from 'react'

const Switch = ( {isChecked, setIsChecked}) => {
  

  const handleCheckboxChange = () => {
    setIsChecked(!isChecked)
  }

  return (
    <>
      <label className='themeSwitcherTwo shadow-card relative inline-flex cursor-pointer select-none items-center justify-center rounded-full bg-[#EFF3F8] p-1 font-family-kbo border '>
        <input
          type='checkbox'
          className='sr-only'
          checked={isChecked}
          onChange={handleCheckboxChange}
        />
        <span
          className={`flex items-center space-x-[6px] rounded-full py-2 px-[18px] text-sm font-medium transition duration-150 ${
            !isChecked ? 'text-[#0d6efd] bg-[#ffffff] shadow-[0px_1px_2px_rgba(0,0,0,0.12)]' : 'text-body-color'
          }`}
        >
          KBO 1군
        </span>
        <span
          className={`flex items-center space-x-[6px] rounded-full py-2 px-[18px] text-sm font-medium transition duration-150 ${
            isChecked ? 'text-[#0d6efd] bg-[#ffffff] shadow-[0px_1px_2px_rgba(0,0,0,0.12)]' : 'text-body-color'
          }`}
        >
          퓨처스리그
        </span>
      </label>
    </>
  )
}

export default Switch;