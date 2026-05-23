import { SnackbarList } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

type SnackbarNotice = {
	id: string;
	content: string;
	type?: string;
	[ key: string ]: unknown;
};

type NoticesSelectors = {
	getNotices: () => SnackbarNotice[];
};

export function NoticesArea() {
	const notices = useSelect(
		( select ) =>
			( select( noticesStore ) as NoticesSelectors )
				.getNotices()
				.filter( ( n ) => n.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			notices={ notices }
			className="components-editor-notices__snackbar"
			onRemove={ removeNotice }
		/>
	);
}
