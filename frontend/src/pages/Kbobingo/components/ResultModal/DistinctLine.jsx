const DistinctLine = ({ text }) => {
    return (
        <div class="inline-flex items-center justify-center w-full">
            <hr class="h-1 my-4 bg-gray-200 border-0 w-full " />
            <span class="absolute px-3 sm:text-xl font-bold text-gray-900 -translate-x-1/2 bg-white left-1/2">
                {text}
            </span>
        </div>
    );
};

export default DistinctLine;
