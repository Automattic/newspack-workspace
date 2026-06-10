export const Logo = ( { className }: { className?: string } ) => {
	return (
		<svg
			className={ className }
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 528 528"
		>
			<path
				d="M264 0C118.204 0 0 118.204 0 264s118.187 264 264 264c145.796 0 264-118.204 264-264S409.796 0 264 0Zm52 384L192 260v124h-48V144l239.999 240H316Zm68-68-36-36h36v36Zm0-68h-68l-36-36h104v36Zm0-68H248l-36-36h172v36Z"
				fill="#fff"
			/>
		</svg>
	);
};
